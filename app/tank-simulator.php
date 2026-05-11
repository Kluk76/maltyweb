<?php
declare(strict_types=1);

/**
 * TankSimulator — event-sourced tank occupancy model.
 *
 * Ported from maltytask/scripts/parse-tank-simulation.js.
 * Reads exclusively from MySQL (no Google Sheets at runtime).
 *
 * Algorithm (chronological event replay):
 *   COOLING  → beer enters CCT
 *   RACKING  → CCT empties, BBT fills (with blend support)
 *   PACKAGING→ BBT depletes
 *
 * State invariants:
 *   - A CCT receiving a new batch implicitly clears the previous occupant.
 *   - BBT volumes below 2.5 HL are treated as empty (dead volume / heel).
 *   - Contents older than 180 days are force-expired.
 *   - Packaging events whose racking form arrived late are deferred and
 *     applied as soon as the racking event is processed.
 *
 * Returns:
 *   [
 *     'cct' => [ tankNumber => stateArray|null, ... ],
 *     'bbt' => [ tankNumber => stateArray|null, ... ],
 *   ]
 *   stateArray keys: beer, batch, volume_hl, filled_date (DateTimeImmutable),
 *                    blend_info (array of {batch, vol} for BBTs only)
 */
class TankSimulator
{
    private const RACKING_LOSS_HL        = 0.9;
    private const PACKAGING_LOSS_HL      = 0.15;
    private const MAX_TANK_AGE_DAYS      = 180;
    private const BBT_EMPTY_THRESHOLD_HL = 2.5;
    private const SIM_START_DATE         = '2023-10-01';
    private const RECENT_COOLING_MONTHS  = 3;

    // ── Beer-name normalisation (ported from lib/beer.js _BEER_NAME_MAP) ──────
    private const BEER_NAME_MAP = [
        'DGD'                 => 'DrunkBeard - Galactic Drift',
        'QDG'                 => 'Qrew - Diversion Gose',
        'docf'                => 'Dockeuse',
        'docb'                => 'Dockeuse',
        'Les Docks - NEIPA'   => 'Dockeuse',
        'Diversion Blanche'   => 'Div.Blanche',
        'Diversion Gose'      => 'Div.Gose',
        'Diversion Panaché'   => 'Div.Panaché',
        'MeltingPote - IPA'   => 'MeltingPote - Cropette',
        'TM-BLO'              => 'Brasserie 28 - Blonde',
        'TM-IPA'              => 'Brasserie 28 - IPA',
        'TM-TR'               => 'Brasserie 28 - Triple',
        'NYL'                 => 'NYL (Hard Seltzer)',
    ];

    // ── Beer-type map (ported from lib/beer.js BEER_TYPE_MAP) ─────────────────
    private const BEER_TYPE_MAP = [
        'Zepp'                        => 'Core',
        'Embuscade'                   => 'Core',
        'Moonshine'                   => 'Core',
        'Stirling'                    => 'Core',
        'Speakeasy'                   => 'Core',
        'Diversion'                   => 'Core',
        'Double Oat'                  => 'Core',
        'Div.Blanche'                 => 'Core',
        'Alternative'                 => 'Core',
        'Estafette'                   => 'Core',
        'Dockeuse'                    => 'Contract',
        'EPH1'                        => 'Ephémère',
        'EPH2'                        => 'Ephémère',
        'EPH3'                        => 'Ephémère',
        'EPH4'                        => 'Ephémère',
        'DrunkBeard - Galactic Drift' => 'Collab',
        'Div.Gose'                    => 'Collab',
        'Div.Panaché'                 => 'Archive',
        'Blonde des Romands'          => 'Archive',
        'NYL (Hard Seltzer)'          => 'Archive',
        'Brasserie 28 - Blonde'       => 'Contract',
        'Brasserie 28 - IPA'          => 'Contract',
        'Brasserie 28 - Triple'       => 'Contract',
        'Chien Bleu - Moût Chaud'     => 'Wort Sale',
        'Chien Bleu - Moût Froid'     => 'Wort Sale',
    ];

    // Wort-sale beers never enter tanks — skip entirely.
    private const WORT_SALES = [
        'Chien Bleu - Moût Chaud',
        'Chien Bleu - Moût Froid',
    ];

    // ── SKU prefix → canonical beer (ported from lib/beer.js _SKU_BEER_MAP) ────
    // bd_packaging.beer holds SKU codes like "STI4", "SPYF" — not canonical names.
    // deriveBeerFromSku() strips the format suffix and looks up the prefix here.
    private const SKU_BEER_MAP = [
        'ZEP'  => 'Zepp',          'EMB'  => 'Embuscade',     'MOO'  => 'Moonshine',
        'STI'  => 'Stirling',      'SPY'  => 'Speakeasy',     'DIV'  => 'Diversion',
        'DOA'  => 'Double Oat',    'EST'  => 'Estafette',     'ALT'  => 'Alternative',
        'DIB'  => 'Div.Blanche',   'DIG'  => 'Div.Gose',      'DIP'  => 'Div.Panaché',
        'DGD'  => 'DrunkBeard - Galactic Drift',              'QDG'  => 'Qrew - Diversion Gose',
        'BLO'  => 'Blonde des Romands', 'BLA' => 'Div.Blanche', 'DOC' => 'Dockeuse',
        'EPH1' => 'EPH1', 'EPH2' => 'EPH2', 'EPH3' => 'EPH3', 'EPH4' => 'EPH4',
    ];

    public function __construct(private readonly PDO $pdo) {}

    // ─────────────────────────────────────────────────────────────────────────
    // Public entry point
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * Run the full simulation and return tank state.
     *
     * @param DateTimeImmutable|null $asOf  Point-in-time cutoff.
     *                                       Null = current state (today).
     * @return array{cct: array<int, array|null>, bbt: array<int, array|null>}
     */
    public function run(?DateTimeImmutable $asOf = null): array
    {
        // $now is the reference "today" throughout the simulation.
        // When an as-of date is supplied we freeze it at midnight so that
        // comparisons with date-only strings are consistent.
        $now      = $asOf !== null
            ? $asOf->setTime(23, 59, 59)   // include events on the cutoff day
            : new DateTimeImmutable('now');
        $simStart = new DateTimeImmutable(self::SIM_START_DATE);
        // Mirror JS: new Date(now.getFullYear(), now.getMonth() - N, 1)
        // i.e. first day of the month N months ago — relative to $now so that
        // an as-of date also shifts the recent-cooling window correctly.
        $recentCutoff = new DateTimeImmutable(
            sprintf(
                'first day of -%d months midnight',
                self::RECENT_COOLING_MONTHS
            ),
            $now->getTimezone()
        );
        // Rebuild relative to $now when asOf is provided.
        if ($asOf !== null) {
            $recentCutoff = DateTimeImmutable::createFromFormat(
                'Y-m-d H:i:s',
                $now->modify(sprintf('first day of -%d months midnight', self::RECENT_COOLING_MONTHS))->format('Y-m-d H:i:s')
            );
        }

        // ── 1. Brewday → CCT map ──────────────────────────────────────────────
        $batchCCT = $this->loadBatchCCT($simStart);

        // ── 2. Racked-batch set (for cooling filter) ──────────────────────────
        $rackedBatches = $this->loadRackedBatches();

        // ── 3. Build event list ───────────────────────────────────────────────
        $events = array_merge(
            $this->loadCoolingEvents($simStart, $recentCutoff, $rackedBatches, $batchCCT),
            $this->loadRackingEvents($batchCCT),
            $this->loadPackagingEvents()
        );

        // Sort: by date asc, then sortPriority (RACKING=0 < COOLING=1 < PACKAGING=2)
        usort($events, function (array $a, array $b): int {
            $cmp = $a['date'] <=> $b['date'];
            if ($cmp !== 0) return $cmp;
            return $a['sort_priority'] <=> $b['sort_priority'];
        });

        // ── 4. Pre-build batch→BBT map (handles late racking forms) ──────────
        // Only consider racking events up to $now (the as-of cutoff).
        $batchBBT = [];
        foreach ($events as $e) {
            if ($e['type'] === 'RACKING' && $e['date'] <= $now) {
                $batchBBT[$e['beer'] . '|' . $e['batch']] = $e['bbt'];
            }
        }

        // ── 5. Replay events ──────────────────────────────────────────────────
        /** @var array<string, array|null> $cctState tank# → state|null */
        $cctState = [];
        /** @var array<string, array|null> $bbtState bbt# → state|null */
        $bbtState = [];
        /** @var array<string, float[]> $pendingDeductions bbt# → [hl, ...] */
        $pendingDeductions = [];

        foreach ($events as $e) {
            // As-of cutoff: skip any event after the reference date.
            if ($e['date'] > $now) {
                continue;
            }

            $this->processEvent(
                $e, $cctState, $bbtState, $batchBBT, $pendingDeductions
            );
        }

        // ── 6. Force-expire stale contents ────────────────────────────────────
        // Age is computed relative to $now (the as-of date), not real-now.
        foreach ($cctState as $k => $state) {
            if ($state !== null && $this->isExpired($state['filled_date'], $now)) {
                $cctState[$k] = null;
            }
        }
        foreach ($bbtState as $k => $state) {
            if ($state !== null && $this->isExpired($state['filled_date'], $now)) {
                $bbtState[$k] = null;
            }
        }

        // ── 7. Rekey by integer tank number ──────────────────────────────────
        $cct = [];
        foreach ($cctState as $k => $state) {
            $cct[(int)$k] = $state;
        }
        $bbt = [];
        foreach ($bbtState as $k => $state) {
            $bbt[(int)$k] = $state;
        }

        return ['cct' => $cct, 'bbt' => $bbt];
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Event processors
    // ─────────────────────────────────────────────────────────────────────────

    private function processEvent(
        array   $e,
        array   &$cctState,
        array   &$bbtState,
        array   &$batchBBT,
        array   &$pendingDeductions
    ): void {
        switch ($e['type']) {
            case 'COOLING':
                $cct = $e['cct'];
                $existing = $cctState[$cct] ?? null;
                // Different batch in the same CCT → implicit rack-out
                if (
                    $existing !== null &&
                    ($existing['beer'] !== $e['beer'] || $existing['batch'] !== $e['batch'])
                ) {
                    $cctState[$cct] = null;
                }
                if (!isset($cctState[$cct]) || $cctState[$cct] === null) {
                    $cctState[$cct] = [
                        'beer'        => $e['beer'],
                        'batch'       => $e['batch'],
                        'volume_hl'   => 0.0,
                        'filled_date' => $e['date'],
                        'blend_info'  => null,
                    ];
                }
                $cctState[$cct]['volume_hl'] += $e['vol'];
                break;

            case 'RACKING':
                // Clear CCT
                if ($e['cct'] !== '?') {
                    $cctState[$e['cct']] = null;
                }

                $netRacked = max(0.0, $e['racked_vol'] - self::RACKING_LOSS_HL);
                $bbt       = $e['bbt'];
                $existing  = $bbtState[$bbt] ?? null;

                // A true blend requires: (a) blend_vol > 0, (b) the BBT is occupied,
                // AND (c) the existing beer is the same recipe.  The MySQL
                // blend_volume_hl field is populated on every racking row (set equal to
                // racked_vol_hl by the form), so we cannot use it alone to infer that a
                // prior BBT volume is being preserved.  Checking same-beer guards against
                // cascading rescale of unrelated batches.
                $isTrueBlend = $e['blend_vol'] > 0
                    && $existing !== null
                    && $existing['beer'] === $e['beer'];

                if ($isTrueBlend) {
                    // Blending into existing BBT (same recipe, multi-batch)
                    $blendVol  = (float)$e['blend_vol'];
                    $newTotal  = $netRacked + $blendVol;

                    // Rescale existing blend info proportionally
                    $newBlend = [];
                    if (!empty($existing['blend_info']) && $existing['volume_hl'] > 0) {
                        foreach ($existing['blend_info'] as $bi) {
                            $newBlend[] = [
                                'batch' => $bi['batch'],
                                'vol'   => $bi['vol'] * ($blendVol / $existing['volume_hl']),
                            ];
                        }
                    } else {
                        $newBlend[] = ['batch' => $existing['batch'], 'vol' => $blendVol];
                    }
                    $newBlend[] = ['batch' => $e['batch'], 'vol' => $netRacked];

                    $bbtState[$bbt] = [
                        'beer'        => $e['beer'],
                        'batch'       => $e['batch'],
                        'volume_hl'   => $newTotal,
                        'filled_date' => $e['date'],
                        'blend_info'  => $newBlend,
                    ];
                } else {
                    // Fresh fill or different-recipe replacement
                    $bbtState[$bbt] = [
                        'beer'        => $e['beer'],
                        'batch'       => $e['batch'],
                        'volume_hl'   => $netRacked,
                        'filled_date' => $e['date'],
                        'blend_info'  => [['batch' => $e['batch'], 'vol' => $netRacked]],
                    ];
                }

                $batchBBT[$e['beer'] . '|' . $e['batch']] = $bbt;

                // Apply deferred packaging deductions
                if (!empty($pendingDeductions[$bbt])) {
                    foreach ($pendingDeductions[$bbt] as $deduct) {
                        $bbtState[$bbt]['volume_hl'] = max(
                            0.0,
                            $bbtState[$bbt]['volume_hl'] - $deduct
                        );
                    }
                    unset($pendingDeductions[$bbt]);
                    if ($bbtState[$bbt]['volume_hl'] < self::BBT_EMPTY_THRESHOLD_HL) {
                        $bbtState[$bbt] = null;
                    }
                }
                break;

            case 'PACKAGING':
                $key = $e['beer'] . '|' . $e['batch'];
                $bbt = $batchBBT[$key] ?? null;

                if ($bbt !== null && !empty($bbtState[$bbt])) {
                    // Guard against cross-recipe drain: form submission timestamps
                    // can put a same-day packaging AFTER a same-day racking that
                    // physically replaced the packaged content (e.g. operator
                    // submits Jasper rack form before submitting earlier Bamse
                    // packaging form — physically Bamse was emptied first, then
                    // Jasper filled).  If the BBT's current beer differs, the
                    // packaged content is gone — skip the deduction.
                    if ($bbtState[$bbt]['beer'] === $e['beer']) {
                        $deduct = $e['hl'] + self::PACKAGING_LOSS_HL;
                        $bbtState[$bbt]['volume_hl'] = max(
                            0.0,
                            $bbtState[$bbt]['volume_hl'] - $deduct
                        );
                        if ($bbtState[$bbt]['volume_hl'] < self::BBT_EMPTY_THRESHOLD_HL) {
                            $bbtState[$bbt] = null;
                        }
                    }
                    // else: BBT now holds a different recipe; the packaged batch
                    // already drained off the floor before this BBT was re-filled.
                } elseif ($bbt !== null && empty($bbtState[$bbt])) {
                    // Racking form not yet seen — defer
                    $pendingDeductions[$bbt][] = $e['hl'] + self::PACKAGING_LOSS_HL;
                }
                break;
        }
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Data loaders
    // ─────────────────────────────────────────────────────────────────────────

    /** Returns ['beer|batch' => cct#string, ...] */
    private function loadBatchCCT(DateTimeImmutable $simStart): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT bd_beer, bd_batch, bd_cct
             FROM bd_brewing_brewday
             WHERE bd_cct IS NOT NULL
               AND event_date >= :start'
        );
        $stmt->execute([':start' => $simStart->format('Y-m-d')]);

        $map = [];
        foreach ($stmt->fetchAll() as $row) {
            $beer  = $this->normalizeBeerName($row['bd_beer'] ?? '');
            $batch = trim($row['bd_batch'] ?? '');
            $cct   = (string)(int)$row['bd_cct'];
            if ($beer !== '' && $batch !== '' && !$this->isWortSale($beer)) {
                $map[$beer . '|' . $batch] = $cct;
            }
        }
        return $map;
    }

    /** Returns Set (associative array keyed by 'beer|batch') of all batches that appear in racking data. */
    private function loadRackedBatches(): array
    {
        $rows = $this->pdo->query(
            'SELECT COALESCE(neb_beer, contract_beer)   AS beer,
                    COALESCE(neb_batch, contract_batch) AS batch
             FROM bd_racking
             WHERE COALESCE(neb_beer, contract_beer)   IS NOT NULL
               AND COALESCE(neb_batch, contract_batch) IS NOT NULL'
        )->fetchAll();

        $set = [];
        foreach ($rows as $row) {
            $beer  = $this->normalizeBeerName($row['beer'] ?? '');
            $batch = trim($row['batch'] ?? '');
            if ($beer !== '' && $batch !== '') {
                $set[$beer . '|' . $batch] = true;
            }
        }
        return $set;
    }

    /** Build COOLING events with the (racked-or-recent) filter. */
    private function loadCoolingEvents(
        DateTimeImmutable $simStart,
        DateTimeImmutable $recentCutoff,
        array             $rackedBatches,
        array             $batchCCT
    ): array {
        $stmt = $this->pdo->prepare(
            'SELECT cool_beer, cool_batch, cool_final_volume_hl, event_date
             FROM bd_brewing_cooling
             WHERE event_date >= :start
               AND cool_final_volume_hl > 0'
        );
        $stmt->execute([':start' => $simStart->format('Y-m-d')]);

        $events = [];
        foreach ($stmt->fetchAll() as $row) {
            $beer  = $this->normalizeBeerName($row['cool_beer'] ?? '');
            $batch = trim($row['cool_batch'] ?? '');
            $vol   = (float)($row['cool_final_volume_hl'] ?? 0);
            $date  = $this->parseDate($row['event_date'] ?? '');

            if ($beer === '' || $batch === '' || $date === null || $vol <= 0) continue;
            if ($this->isWortSale($beer)) continue;

            $key    = $beer . '|' . $batch;
            $cct    = $batchCCT[$key] ?? '?';
            $isRacked  = isset($rackedBatches[$key]);
            $isRecent  = $date >= $recentCutoff;

            if (!$isRacked && !$isRecent) continue;

            $events[] = [
                'type'          => 'COOLING',
                'date'          => $date,
                'beer'          => $beer,
                'batch'         => $batch,
                'cct'           => $cct,
                'vol'           => $vol,
                'sort_priority' => 1,
            ];
        }
        return $events;
    }

    /** Build RACKING events. */
    private function loadRackingEvents(array $batchCCT): array
    {
        // blend_text holds BSF col Q ("Blend" = the leftover-from-previous-batch
        // volume in HL, mostly "0", sometimes a real value like "42").  It is the
        // ONLY signal that a true blend should happen.  blend_volume_hl maps to
        // BSF col W which is a formula = racked_vol + blend_leftover (total post-
        // rack) — NOT the blend leftover.  Reading col W as blendVol caused every
        // rack to be treated as a blend and inflated BBT volumes 2-3x.
        $rows = $this->pdo->query(
            'SELECT COALESCE(neb_beer, contract_beer)   AS beer,
                    COALESCE(neb_batch, contract_batch) AS batch,
                    bbt,
                    bbt_old,
                    racked_vol_hl,
                    blend_text,
                    COALESCE(start_time, submitted_at)  AS rack_date
             FROM bd_racking
             WHERE COALESCE(start_time, submitted_at) IS NOT NULL'
        )->fetchAll();

        $events = [];
        foreach ($rows as $row) {
            $beer     = $this->normalizeBeerName($row['beer'] ?? '');
            $batch    = trim($row['batch'] ?? '');
            $bbt      = $this->extractBbtNumber($row['bbt'] ?? null, $row['bbt_old'] ?? null);
            $rackedVol = (float)($row['racked_vol_hl'] ?? 0);
            // blend_text is stored as VARCHAR — coerce to float, falling back to 0
            // for non-numeric content (e.g. operator wrote text instead of a number).
            $blendVol  = is_numeric($row['blend_text'] ?? '') ? (float)$row['blend_text'] : 0.0;
            $date      = $this->parseDate($row['rack_date'] ?? '');

            if ($beer === '' || $batch === '' || $bbt === null || $date === null) continue;
            if ($this->isWortSale($beer)) continue;

            $key = $beer . '|' . $batch;
            $cct = $batchCCT[$key] ?? '?';

            $events[] = [
                'type'          => 'RACKING',
                'date'          => $date,
                'beer'          => $beer,
                'batch'         => $batch,
                'cct'           => $cct,
                'bbt'           => (string)$bbt,
                'racked_vol'    => $rackedVol,
                'blend_vol'     => $blendVol,
                'sort_priority' => 0,
            ];
        }
        return $events;
    }

    /** Build PACKAGING events. */
    private function loadPackagingEvents(): array
    {
        $rows = $this->pdo->query(
            'SELECT beer,
                    batch,
                    vendable_hl,
                    loss_liquid_l,
                    submitted_at
             FROM bd_packaging
             WHERE submitted_at IS NOT NULL
               AND (vendable_hl > 0 OR loss_liquid_l > 0)'
        )->fetchAll();

        $events = [];
        foreach ($rows as $row) {
            // bd_packaging.beer holds SKU codes like "SPYF" / "STI4" — strip the
            // format suffix to recover the prefix then map to canonical beer.
            // Without this, packaging events never match the canonical-named
            // batchBBT entries set by racking and BBT volumes never drain.
            $rawBeer       = trim($row['beer'] ?? '');
            $beer          = $this->deriveBeerFromSku($rawBeer);
            if ($beer === '') {
                // Fallback: maybe the col already holds a canonical name (older rows).
                $beer = $this->normalizeBeerName($rawBeer);
            }
            $batch         = trim($row['batch'] ?? '');
            $vendableHl    = (float)($row['vendable_hl'] ?? 0);
            $lossLiquidL   = (float)($row['loss_liquid_l'] ?? 0);
            $totalHl       = $vendableHl + ($lossLiquidL / 100.0); // litres → HL
            $date          = $this->parseDate($row['submitted_at'] ?? '');

            if ($beer === '' || $date === null || $totalHl <= 0) continue;
            if ($this->isWortSale($beer)) continue;

            $events[] = [
                'type'          => 'PACKAGING',
                'date'          => $date,
                'beer'          => $beer,
                'batch'         => $batch,
                'hl'            => $totalHl,
                'sort_priority' => 2,
            ];
        }
        return $events;
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Beer helpers (ported from lib/beer.js)
    // ─────────────────────────────────────────────────────────────────────────

    private function normalizeBeerName(string $raw): string
    {
        $trimmed = trim($raw);
        if ($trimmed === '') return '';
        return self::BEER_NAME_MAP[$trimmed] ?? $trimmed;
    }

    public static function getBeerType(string $beer): string
    {
        if (isset(self::BEER_TYPE_MAP[$beer])) return self::BEER_TYPE_MAP[$beer];
        if ($beer !== '' && str_contains($beer, ' - ')) return 'Contract';
        return 'Other';
    }

    private function isWortSale(string $beer): bool
    {
        return in_array($beer, self::WORT_SALES, true);
    }

    /**
     * Derive canonical beer name from an SKU code (e.g. "SPYF" → "Speakeasy",
     * "EMB4" → "Embuscade", "EPH2B" → "EPH2"). Returns '' if no prefix matches.
     * Tries the 4-char prefix first to catch EPH1..4, then falls back to 3-char.
     */
    private function deriveBeerFromSku(string $sku): string
    {
        if ($sku === '') return '';
        $s = strtoupper($sku);
        // EPH1..4 are 4-char prefixes; try longest first
        if (preg_match('/^EPH[1-4]/', $s)) {
            return self::SKU_BEER_MAP[substr($s, 0, 4)] ?? '';
        }
        return self::SKU_BEER_MAP[substr($s, 0, 4)]
            ?? self::SKU_BEER_MAP[substr($s, 0, 3)]
            ?? '';
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Utility helpers
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * Extract BBT number from the text column ("BBT 7" → 7) with
     * fallback to the legacy integer column.
     */
    private function extractBbtNumber(?string $bbtText, ?int $bbtOld): ?int
    {
        if ($bbtText !== null && $bbtText !== '') {
            if (preg_match('/(\d+)/', $bbtText, $m)) {
                return (int)$m[1];
            }
        }
        return ($bbtOld !== null) ? (int)$bbtOld : null;
    }

    /** Parse a date/datetime string into DateTimeImmutable; returns null on failure. */
    private function parseDate(string $raw): ?DateTimeImmutable
    {
        if ($raw === '' || $raw === '0000-00-00' || $raw === '0000-00-00 00:00:00') {
            return null;
        }
        $dt = DateTimeImmutable::createFromFormat('Y-m-d H:i:s.u', $raw)
           ?: DateTimeImmutable::createFromFormat('Y-m-d H:i:s', $raw)
           ?: DateTimeImmutable::createFromFormat('Y-m-d H:i:s', substr($raw, 0, 19))
           ?: DateTimeImmutable::createFromFormat('Y-m-d', substr($raw, 0, 10))
           ?: null;
        return $dt;
    }

    private function isExpired(DateTimeImmutable $filledDate, DateTimeImmutable $now): bool
    {
        $diffDays = (int)$filledDate->diff($now)->days;
        return $diffDays > self::MAX_TANK_AGE_DAYS;
    }
}
