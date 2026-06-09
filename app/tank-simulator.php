<?php
declare(strict_types=1);

require_once __DIR__ . '/recipe-resolver.php';

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
 *   stateArray keys: beer, raw_beer, batch, volume_hl, filled_date (DateTimeImmutable),
 *                    blend_info (array of {batch, vol} for BBTs only)
 */
class TankSimulator
{
    // Default fallback values — used when commissioning_settings rows are absent.
    // MUST match migration 184 seed values (0.9000 / 0.1500) byte-for-byte.
    // These feed WIP and COGS calculations — any drift is a silent COGS bug.
    private const RACKING_LOSS_HL        = 0.9;
    private const PACKAGING_LOSS_HL      = 0.15;

    // Instance props loaded from commissioning_settings in __construct().
    // Coalesce to class consts when the DB row is absent (first-run safety).
    private float $rackingLossHl;
    private float $packagingLossHl;
    private float $bbtEmptyThresholdHl;
    private const MAX_TANK_AGE_DAYS      = 180;
    private const BBT_EMPTY_THRESHOLD_HL = 2.5;
    private const SIM_START_DATE         = '2023-10-01';
    private const RECENT_COOLING_MONTHS  = 3;

    // ── Beer-name normalisation ───────────────────────────────────────────────
    //
    // WHY THIS MAP STAYS (not replaced by recipe-resolver):
    //   bd_racking stores abbreviated canonical names (e.g. "Div.Blanche",
    //   "Div.Gose") while bd_brewing_brewday stores full canonical names
    //   ("Diversion Blanche", "Diversion Gose"). The simulator uses a single
    //   internal key space (keyed by beer name) across both tables, so it needs
    //   to normalise both sides to the SAME string. recipe-resolver.php would
    //   return "Diversion Blanche" (the ref_recipes canonical), which would fail
    //   to match the bd_racking rows that store "Div.Blanche". Until the DB data
    //   is normalised (a separate migration), this map is the source-of-truth
    //   for the simulator's internal canonical. See also: BEER_TO_PREFIX below.
    //
    // Additionally, TM-BLO/TM-IPA/TM-TR appear in bd_brewing_brewday with those
    // exact codes, so we do NOT remap them — mapping to 'Brasserie 28 - *' would
    // break the brewday→racking key join. Those three entries have been removed.
    // NYL appears in bd_brewing_brewday as 'NYL' (not 'NYL (Hard Seltzer)'), so
    // it too has been removed from this map.
    private const BEER_NAME_MAP = [
        'DGD'               => 'DrunkBeard - Galactic Drift',
        'QDG'               => 'Qrew - Diversion Gose',
        'docf'              => 'Docks - NEIPA',
        'docb'              => 'Docks - NEIPA',
        'Les Docks - NEIPA' => 'Docks - NEIPA',
        'Dockeuse'          => 'Docks - NEIPA',
        'Diversion Blanche' => 'Div.Blanche',
        'Diversion Gose'    => 'Div.Gose',
        'Diversion Panaché' => 'Div.Panaché',
        'MeltingPote - IPA' => 'MeltingPote - Cropette',
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
        'Docks - NEIPA'               => 'Contract',
        'EPH1'                        => 'Ephémère',
        'EPH2'                        => 'Ephémère',
        'EPH3'                        => 'Ephémère',
        'EPH4'                        => 'Ephémère',
        'DrunkBeard - Galactic Drift' => 'Collab',
        'Div.Gose'                    => 'Collab',
        'Div.Panaché'                 => 'Archive',
        'Blonde des Romands'          => 'Archive',
        'NYL'                         => 'Archive',
        'TM-BLO'                      => 'Contract',
        'TM-IPA'                      => 'Contract',
        'TM-TR'                       => 'Contract',
        'Chien Bleu - Moût Chaud'     => 'Wort Sale',
        'Chien Bleu - Moût Froid'     => 'Wort Sale',
    ];

    // Wort-sale beers never enter tanks — skip entirely.
    private const WORT_SALES = [
        'Chien Bleu - Moût Chaud',
        'Chien Bleu - Moût Froid',
    ];

    // ── SKU prefix → simulator-internal canonical beer name ──────────────────
    //
    // WHY THIS MAP STAYS (not replaced by recipe-resolver):
    //   deriveBeerFromSku() strips the format suffix and maps the SKU prefix to
    //   the simulator's INTERNAL canonical name. This must match BEER_NAME_MAP's
    //   output space — e.g. DIB must resolve to 'Div.Blanche' (not 'Diversion
    //   Blanche') so events join correctly to racking events that used 'Div.Blanche'.
    //   recipe-resolver returns 'Diversion Blanche' (the ref_recipes canonical),
    //   which would create a mismatch. Fix path: normalise bd_racking.neb_beer
    //   to use full canonical names, then drop both maps and use the resolver.
    private const SKU_BEER_MAP = [
        'ZEP'  => 'Zepp',          'EMB'  => 'Embuscade',     'MOO'  => 'Moonshine',
        'STI'  => 'Stirling',      'SPY'  => 'Speakeasy',     'DIV'  => 'Diversion',
        'DOA'  => 'Double Oat',    'EST'  => 'Estafette',     'ALT'  => 'Alternative',
        'DIB'  => 'Div.Blanche',   'DIG'  => 'Div.Gose',      'DIP'  => 'Div.Panaché',
        'DGD'  => 'DrunkBeard - Galactic Drift',              'QDG'  => 'Qrew - Diversion Gose',
        'BLO'  => 'Blonde des Romands', 'BLA' => 'Div.Blanche', 'DOC' => 'Docks - NEIPA',
        'EPH1' => 'EPH1', 'EPH2' => 'EPH2', 'EPH3' => 'EPH3', 'EPH4' => 'EPH4',
    ];

    // ── Beer → SRM-realistic fill colour for tank visualisation ──────────────
    // Hex strings (not CSS vars) so they can be used inside SVG defs/gradients.
    // Order roughly by SRM colour scale: pale gold → amber → mahogany → black.
    // Operator can tweak any value here — these become the canonical visual
    // identity of each recipe on the tank board.
    private const BEER_COLOUR_MAP = [
        'Zepp'                        => '#e0b94a',  // Czech-style lager, pale gold
        'Moonshine'                   => '#d8c478',  // wit (coriander/orange peel), pale wheat cloudy
        'Div.Blanche'                 => '#d6c993',  // witbier white, very pale
        'Div.Gose'                    => '#e3c98a',  // gose, pale with slight salt cast
        'Div.Panaché'                 => '#ebd9a6',  // radler-style, very pale
        'Blonde des Romands'          => '#dfbd4d',  // blonde lager
        'Diversion'                   => '#cf9c2c',  // IPA, golden-amber
        'Alternative'                 => '#cd902f',  // hazy IPA
        'Docks - NEIPA'               => '#d2a13e',  // NEIPA contract, hazy gold
        'Embuscade'                   => '#a8651e',  // amber IPA / pale ale
        'Estafette'                   => '#b86d22',  // saison, orange-amber
        'Stirling'                    => '#7d4218',  // Scotch ale / darker amber
        'DrunkBeard - Galactic Drift' => '#9c5320',  // collab IPA, amber
        'EPH1'                        => '#b87a30',  // ephémère default (operator overrides per vintage)
        'EPH2'                        => '#b87a30',
        'EPH3'                        => '#b87a30',
        'EPH4'                        => '#b87a30',
        'Speakeasy'                   => '#3a1809',  // dark stout / porter
        'Double Oat'                  => '#1a0c06',  // very dark oat stout
        'Qrew - Diversion Gose'       => '#e3c98a',  // gose collab
        // Contract beers (default amber unless overridden)
        'TM-BLO'                      => '#dfbd4d',
        'TM-IPA'                      => '#c08428',
        'TM-TR'                       => '#c79938',
    ];

    // Fallback for unknown beers / contract beers not in the map.
    public const DEFAULT_BEER_COLOUR = '#a06030';  // oak amber

    /** Resolve a canonical beer name to its tank-fill colour (hex string). */
    public static function beerColour(string $beer): string
    {
        if (isset(self::BEER_COLOUR_MAP[$beer])) return self::BEER_COLOUR_MAP[$beer];
        // Contract beers with " - " separator get a slightly cooler default
        // so they read as visually distinct from the Neb core range.
        if (str_contains($beer, ' - ')) return '#8c5530';
        return self::DEFAULT_BEER_COLOUR;
    }

    // ── Simulator-internal canonical → short prefix used in fermenting cells ──
    //
    // Operators type "<PREFIX> <BATCH>" in bd_fermenting columns like
    // beers_to_cold_crash / beers_to_read (e.g. "DIB 6", "EMB 232", "EPH2 26").
    // For Neb beers the prefix is a 3-or-4-letter SKU code; for contract beers
    // the operator types the full canonical name (e.g. "Chien Bleu - Jasper 28").
    // Without this, a batch-only LIKE match cross-matches across beers.
    //
    // AUTHORITATIVE equivalent in recipe-resolver.php: canonical_to_short_code().
    // Note: that function maps ref_recipes canonical names (e.g. "Diversion Blanche")
    // whereas this map uses simulator-internal names (e.g. "Div.Blanche"). Both
    // produce the same short code (DIB). tanks.php uses canonical_to_short_code()
    // directly; this map remains for the static beerPrefix() method.
    private const BEER_TO_PREFIX = [
        'Zepp'               => 'ZEP',
        'Embuscade'          => 'EMB',
        'Moonshine'          => 'MOO',
        'Stirling'           => 'STI',
        'Speakeasy'          => 'SPY',
        'Diversion'          => 'DIV',
        'Double Oat'         => 'DOA',
        'Estafette'          => 'EST',
        'Alternative'        => 'ALT',
        'Div.Blanche'        => 'DIB',
        'Div.Gose'           => 'DIG',
        'Div.Panaché'        => 'DIP',
        'Blonde des Romands' => 'BLO',
        'Docks - NEIPA'      => 'DOC',
        'EPH1' => 'EPH1', 'EPH2' => 'EPH2', 'EPH3' => 'EPH3', 'EPH4' => 'EPH4',
        'DrunkBeard - Galactic Drift' => 'DGD',
        'Qrew - Diversion Gose'       => 'QDG',
    ];

    /**
     * Return the short prefix operators type alongside the batch number in
     * fermenting cells. Falls back to the full canonical name for contract
     * beers that the operator references by full name.
     *
     * Note: tanks.php now calls canonical_to_short_code($pdo, $beer) from
     * recipe-resolver.php directly (which works on ref_recipes canonical names).
     * This static method is kept for callers that have no PDO in scope.
     */
    public static function beerPrefix(string $beer): string
    {
        return self::BEER_TO_PREFIX[$beer] ?? $beer;
    }

    public function __construct(private readonly PDO $pdo)
    {
        // ONE read at construction time — no per-event DB hits.
        // Fetches the two COGS-critical HL loss constants from commissioning_settings.
        // Coalesces to class-const defaults when the DB row is absent (safe bootstrap).
        $stmt = $this->pdo->prepare(
            "SELECT key_name, value_num
               FROM commissioning_settings
              WHERE section = 'pertes'
                AND key_name IN ('pertes_racking_loss_hl', 'pertes_packaging_loss_hl',
                                 'pertes_bbt_empty_threshold_hl')
                AND is_active = 1"
        );
        $stmt->execute();
        $pertes = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $pertes[$row['key_name']] = (float) $row['value_num'];
        }
        $this->rackingLossHl       = $pertes['pertes_racking_loss_hl']        ?? self::RACKING_LOSS_HL;
        $this->packagingLossHl     = $pertes['pertes_packaging_loss_hl']      ?? self::PACKAGING_LOSS_HL;
        $this->bbtEmptyThresholdHl = $pertes['pertes_bbt_empty_threshold_hl'] ?? self::BBT_EMPTY_THRESHOLD_HL;
    }

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
        // Key strategy: (recipe_id, batch) when recipe_id is known; (beer, batch) fallback
        // for sparse edges where recipe_id is NULL. recipe_id keying is immune to the
        // neb_beer fragmentation problem (SKU codes STI4/SPYB/EMB4C vs canonical names)
        // that caused BBT under-drain. Both keys are stored so PACKAGING lookups can
        // try recipe_id-based key first, then fall back to beer-name key.
        $batchBBT = [];
        foreach ($events as $e) {
            if ($e['type'] === 'RACKING' && $e['date'] <= $now) {
                // Store under both key forms for lookup fallback compatibility.
                $batchBBT[$e['beer'] . '|' . $e['batch']] = $e['bbt'];
                if (($e['recipe_id'] ?? null) !== null) {
                    $batchBBT['rid:' . $e['recipe_id'] . '|' . $e['batch']] = $e['bbt'];
                }
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
                // Different batch in the same CCT → implicit rack-out.
                // Identity check uses (recipe_id, batch) when both sides have a recipe_id;
                // falls back to (beer, batch) when either side is NULL (sparse edge).
                if ($existing !== null) {
                    $existingRecipeId = $existing['recipe_id'] ?? null;
                    $eventRecipeId    = $e['recipe_id'] ?? null;
                    $sameOccupant = ($existingRecipeId !== null && $eventRecipeId !== null)
                        ? ($existingRecipeId === $eventRecipeId && $existing['batch'] === $e['batch'])
                        : ($existing['beer'] === $e['beer']     && $existing['batch'] === $e['batch']);
                    if (!$sameOccupant) {
                        $cctState[$cct] = null;
                    }
                }
                if (!isset($cctState[$cct]) || $cctState[$cct] === null) {
                    $cctState[$cct] = [
                        'beer'        => $e['beer'],
                        'raw_beer'    => $e['raw_beer'],
                        'batch'       => $e['batch'],
                        'recipe_id'   => $e['recipe_id'] ?? null,
                        'volume_hl'   => 0.0,
                        'filled_date' => $e['date'],
                        'blend_info'  => null,
                    ];
                } else {
                    // Update raw_beer from the latest cooling row for this batch
                    $cctState[$cct]['raw_beer'] = $e['raw_beer'];
                }
                $cctState[$cct]['volume_hl'] += $e['vol'];
                break;

            case 'RACKING':
                // ── Source CCT occupancy ──────────────────────────────────────
                // NORMAL rack (interrupted_flag != 1): full-empty the CCT (unchanged behaviour).
                // INTERRUPTED rack (interrupted_flag = 1): partial decrement.
                //   newVol = source_vol − racked_vol_hl − loss_source_hl
                //   If newVol ≤ BBT_EMPTY_THRESHOLD_HL (no CCT-specific threshold exists;
                //   reuse BBT's 2.5 HL dead-volume floor as a conservative epsilon):
                //     null the CCT (it emptied despite the interruption).
                //   Else: keep CCT occupied with the same beer/batch at reduced volume.
                //   racked_vol_hl = 0 AND interrupted: source completely unchanged (0 HL left CCT).
                if ($e['cct'] !== '?') {
                    $interrupted = (int)($e['interrupted_flag'] ?? 0);
                    if ($interrupted !== 1) {
                        // Normal rack — full-empty the source CCT, but ONLY if the CCT's
                        // current occupant is the same batch being racked.
                        // Guard against cross-batch drain: a late/out-of-order racking
                        // form may reference a CCT that a NEWER batch has since refilled.
                        // If the CCT holds a different batch, the rack-out is a no-op on
                        // the CCT (that batch already left; its volume is already gone)
                        // but we still proceed to fill/blend the destination BBT below.
                        // Mirrors the analogous BBT cross-recipe guard on ~L551.
                        $sourceState = $cctState[$e['cct']] ?? null;
                        if ($sourceState !== null) {
                            // Identity check uses (recipe_id, batch) when both sides have
                            // a recipe_id; falls back to (beer, batch) for sparse edges.
                            $srcRecipeId   = $sourceState['recipe_id'] ?? null;
                            $evtRecipeId   = $e['recipe_id'] ?? null;
                            $sameOccupant  = ($srcRecipeId !== null && $evtRecipeId !== null)
                                ? ($srcRecipeId === $evtRecipeId && $sourceState['batch'] === $e['batch'])
                                : ($sourceState['beer'] === $e['beer'] && $sourceState['batch'] === $e['batch']);
                            if ($sameOccupant) {
                                $cctState[$e['cct']] = null;
                            }
                            // else: CCT now holds a newer batch — leave it occupied.
                        }
                        // CCT was already null — nothing to drain.
                    } else {
                        // Interrupted rack — partial decrement only.
                        $sourceState = $cctState[$e['cct']] ?? null;
                        if ($sourceState === null) {
                            // CCT was already empty/null — nothing to decrement; leave null.
                        } else {
                            // Same identity guard as the normal-rack path above.
                            $srcRecipeId   = $sourceState['recipe_id'] ?? null;
                            $evtRecipeId   = $e['recipe_id'] ?? null;
                            $sameOccupant  = ($srcRecipeId !== null && $evtRecipeId !== null)
                                ? ($srcRecipeId === $evtRecipeId && $sourceState['batch'] === $e['batch'])
                                : ($sourceState['beer'] === $e['beer'] && $sourceState['batch'] === $e['batch']);
                            if (!$sameOccupant) {
                                // Interrupted rack on a different occupant — no-op on CCT.
                                // (Proceed to fill/blend BBT below regardless.)
                            } else {
                                $sourceVol  = (float)$sourceState['volume_hl'];
                                $rackedOut  = (float)($e['racked_vol'] ?? 0.0);
                                $lostAtSrc  = (float)($e['loss_source_hl'] ?? 0.0);
                                $remaining  = $sourceVol - $rackedOut - $lostAtSrc;
                                if ($remaining <= $this->bbtEmptyThresholdHl) {
                                    // Remaining below dead-volume floor → treat as emptied.
                                    $cctState[$e['cct']] = null;
                                } else {
                                    // Partial fill — keep CCT occupied at reduced volume.
                                    $cctState[$e['cct']]['volume_hl'] = $remaining;
                                }
                            }
                        }
                    }
                }

                $netRacked = max(0.0, $e['racked_vol'] - $this->rackingLossHl);
                $bbt       = $e['bbt'];
                $existing  = $bbtState[$bbt] ?? null;

                // A true blend requires: (a) blend_vol > 0, (b) the BBT is occupied,
                // AND (c) the existing content is the same recipe.  The MySQL
                // blend_volume_hl field is populated on every racking row (set equal to
                // racked_vol_hl by the form), so we cannot use it alone to infer that a
                // prior BBT volume is being preserved.  Checking same-recipe guards against
                // cascading rescale of unrelated batches.
                // Identity: (recipe_id, batch) when both sides have recipe_id; (beer)
                // otherwise — a blended BBT can hold multiple batches of the same recipe.
                $existingBbtRecipeId = $existing['recipe_id'] ?? null;
                $eventRecipeId       = $e['recipe_id'] ?? null;
                $sameRecipe = ($existingBbtRecipeId !== null && $eventRecipeId !== null)
                    ? ($existingBbtRecipeId === $eventRecipeId)
                    : ($existing !== null && $existing['beer'] === $e['beer']);
                $isTrueBlend = $e['blend_vol'] > 0
                    && $existing !== null
                    && $sameRecipe;

                if ($isTrueBlend) {
                    // Blending into existing BBT (same recipe, multi-batch)
                    $blendVol  = (float)$e['blend_vol'];
                    $newTotal  = $netRacked + $blendVol;

                    // Rescale existing blend info proportionally; preserve recipe_id per lot.
                    $newBlend = [];
                    if (!empty($existing['blend_info']) && $existing['volume_hl'] > 0) {
                        foreach ($existing['blend_info'] as $bi) {
                            $newBlend[] = [
                                'batch'     => $bi['batch'],
                                'recipe_id' => $bi['recipe_id'] ?? null,
                                'vol'       => $bi['vol'] * ($blendVol / $existing['volume_hl']),
                            ];
                        }
                    } else {
                        $newBlend[] = [
                            'batch'     => $existing['batch'],
                            'recipe_id' => $existing['recipe_id'] ?? null,
                            'vol'       => $blendVol,
                        ];
                    }
                    $newBlend[] = [
                        'batch'     => $e['batch'],
                        'recipe_id' => $e['recipe_id'] ?? null,
                        'vol'       => $netRacked,
                    ];

                    $bbtState[$bbt] = [
                        'beer'        => $e['beer'],
                        'raw_beer'    => $existing['raw_beer'] ?? $e['beer'],
                        'batch'       => $e['batch'],
                        'recipe_id'   => $e['recipe_id'] ?? null,
                        'volume_hl'   => $newTotal,
                        'filled_date' => $e['date'],
                        'blend_info'  => $newBlend,
                    ];
                } else {
                    // Fresh fill or different-recipe replacement
                    $bbtState[$bbt] = [
                        'beer'        => $e['beer'],
                        'raw_beer'    => $e['beer'],
                        'batch'       => $e['batch'],
                        'recipe_id'   => $e['recipe_id'] ?? null,
                        'volume_hl'   => $netRacked,
                        'filled_date' => $e['date'],
                        'blend_info'  => [[
                            'batch'     => $e['batch'],
                            'recipe_id' => $e['recipe_id'] ?? null,
                            'vol'       => $netRacked,
                        ]],
                    ];
                }

                $batchBBT[$e['beer'] . '|' . $e['batch']] = $bbt;
                if (($e['recipe_id'] ?? null) !== null) {
                    $batchBBT['rid:' . $e['recipe_id'] . '|' . $e['batch']] = $bbt;
                }

                // C3 — Apply loss_dest_hl AFTER the fill/blend-in.
                // Semantics: operator-entered volume lost at/inside the destination tank
                // (spillage, dead-leg, etc.). Draw-down is pro-rata across the newly blended
                // lot composition so that sum(blend_info[].vol) == volume_hl is maintained.
                // Order: fill/blend-in first (above), THEN loss_dest decrement — this is the
                // correct physical sequence (liquid entered the tank, then some was lost).
                //
                // loss_source_hl: accounted for in the CCT partial-decrement above (C4).
                // Normal racking full-empties the source CCT; interrupted racking uses
                // (source_vol − racked_vol − loss_source_hl) to derive the remaining volume.
                //
                // dest_bbt_still_clean: an event-sourced BBT clean-state attestation captured
                // when interrupted_flag=1 AND racked_vol=0. This flag is NOT consumed by the
                // simulator itself (no stored dirty/clean column on the tank state). Instead,
                // cip_dest_bbt_is_clean() in cip-events.php reads bd_racking_v2 + bd_cip_events
                // chronologically to derive the current clean-state of any BBT at query time.
                // The simulator only stores the event; the CIP gate reads from the event log.
                $lossDestHl = (float)($e['loss_dest_hl'] ?? 0.0);
                if ($lossDestHl > 0.0 && $bbtState[$bbt] !== null) {
                    $prevVol = $bbtState[$bbt]['volume_hl'];
                    $bbtState[$bbt]['volume_hl'] = max(0.0, $prevVol - $lossDestHl);
                    $this->_bbt_prorata_decrement(
                        $bbtState[$bbt]['blend_info'],
                        $prevVol,
                        $lossDestHl
                    );
                    // Null the BBT if it drops below the dead-volume threshold
                    if ($bbtState[$bbt]['volume_hl'] < $this->bbtEmptyThresholdHl) {
                        $bbtState[$bbt] = null;
                    }
                }

                // Apply deferred packaging deductions (racking form arrived late).
                // Each deduction must also shrink blend_info pro-rata so that
                // lot volumes stay consistent with the updated volume_hl.
                if (!empty($pendingDeductions[$bbt])) {
                    foreach ($pendingDeductions[$bbt] as $deduct) {
                        $prevVol = $bbtState[$bbt]['volume_hl'];
                        $bbtState[$bbt]['volume_hl'] = max(0.0, $prevVol - $deduct);
                        $this->_bbt_prorata_decrement(
                            $bbtState[$bbt]['blend_info'],
                            $prevVol,
                            $deduct
                        );
                    }
                    unset($pendingDeductions[$bbt]);
                    if ($bbtState[$bbt]['volume_hl'] < $this->bbtEmptyThresholdHl) {
                        $bbtState[$bbt] = null;
                    }
                }
                break;

            case 'PACKAGING':
                // Key lookup: prefer (recipe_id, batch) when recipe_id is known —
                // immune to neb_beer fragmentation (SKU codes STI4/SPYB vs canonical names).
                // Fall back to (beer, batch) only when recipe_id is NULL (sparse edge).
                // Mirrors the (recipe_id, batch)-with-name-fallback pattern used at CCT/racking.
                $eventRecipeId = $e['recipe_id'] ?? null;
                if ($eventRecipeId !== null) {
                    $ridKey = 'rid:' . $eventRecipeId . '|' . $e['batch'];
                    $bbt    = $batchBBT[$ridKey] ?? $batchBBT[$e['beer'] . '|' . $e['batch']] ?? null;
                } else {
                    $bbt = $batchBBT[$e['beer'] . '|' . $e['batch']] ?? null;
                }

                if ($bbt !== null && !empty($bbtState[$bbt])) {
                    // Guard against cross-recipe drain: form submission timestamps
                    // can put a same-day packaging AFTER a same-day racking that
                    // physically replaced the packaged content (e.g. operator
                    // submits Jasper rack form before submitting earlier Bamse
                    // packaging form — physically Bamse was emptied first, then
                    // Jasper filled). If the BBT's current recipe differs, the
                    // packaged content is gone — skip the deduction.
                    // Identity: (recipe_id, batch) when both sides have recipe_id;
                    // falls back to beer-name compare only when either is NULL.
                    $bbtRecipeId   = $bbtState[$bbt]['recipe_id'] ?? null;
                    $sameOccupant  = ($bbtRecipeId !== null && $eventRecipeId !== null)
                        ? ($bbtRecipeId === $eventRecipeId)
                        : ($bbtState[$bbt]['beer'] === $e['beer']);
                    if ($sameOccupant) {
                        $deduct      = $e['hl'] + $this->packagingLossHl;
                        $prevVol     = $bbtState[$bbt]['volume_hl'];
                        $bbtState[$bbt]['volume_hl'] = max(0.0, $prevVol - $deduct);
                        // Keep blend_info in sync: scale lots pro-rata so their
                        // sum continues to equal the new volume_hl.
                        $this->_bbt_prorata_decrement(
                            $bbtState[$bbt]['blend_info'],
                            $prevVol,
                            $deduct
                        );
                        if ($bbtState[$bbt]['volume_hl'] < $this->bbtEmptyThresholdHl) {
                            $bbtState[$bbt] = null;
                        }
                    }
                    // else: BBT now holds a different recipe; the packaged batch
                    // already drained off the floor before this BBT was re-filled.
                } elseif ($bbt !== null && empty($bbtState[$bbt])) {
                    // Racking form not yet seen — defer
                    $pendingDeductions[$bbt][] = $e['hl'] + $this->packagingLossHl;
                }
                break;
        }
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Data loaders
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * Returns ['beer|batch' => ['cct' => string, 'recipe_id' => int|null], ...]
     * ORDER BY event_date ASC so last-write-wins is deterministic when a
     * (beer, batch) key appears on multiple brewdays (re-brew edge case).
     */
    private function loadBatchCCT(DateTimeImmutable $simStart): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT beer AS bd_beer, batch AS bd_batch, cct AS bd_cct,
                    recipe_id_fk AS bd_recipe_id
             FROM bd_brewing_brewday_v2
             WHERE cct IS NOT NULL
               AND event_date >= :start
             ORDER BY event_date ASC'
        );
        $stmt->execute([':start' => $simStart->format('Y-m-d')]);

        $map = [];
        foreach ($stmt->fetchAll() as $row) {
            $beer  = $this->normalizeBeerName($row['bd_beer'] ?? '');
            $batch = trim($row['bd_batch'] ?? '');
            $cct   = (string)(int)$row['bd_cct'];
            if ($beer !== '' && $batch !== '' && !$this->isWortSale($beer)) {
                // Last-write-wins (ORDER BY event_date ASC means later rows
                // overwrite earlier ones — deterministic for re-brew edge cases).
                $map[$beer . '|' . $batch] = [
                    'cct'       => $cct,
                    'recipe_id' => isset($row['bd_recipe_id']) ? (int)$row['bd_recipe_id'] : null,
                ];
            }
        }
        return $map;
    }

    /** Returns Set (associative array keyed by 'beer|batch') of all batches that appear in racking data. */
    private function loadRackedBatches(): array
    {
        $rows = $this->pdo->query(
            'SELECT COALESCE(NULLIF(neb_beer, ""), contract_beer)   AS beer,
                    COALESCE(NULLIF(neb_batch, ""), contract_batch) AS batch
             FROM bd_racking_v2
             WHERE COALESCE(NULLIF(neb_beer, ""), contract_beer)   IS NOT NULL
               AND COALESCE(NULLIF(neb_batch, ""), contract_batch) IS NOT NULL'
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
        // bd_brewing_cooling folded into bd_brewing_gravity_v2 WHERE event_type='Cooling'.
        // gravity_v2 has no event_date column — derive it from DATE(submitted_at).
        // recipe_id_fk is 100% populated on bd_brewing_gravity_v2 (pre-flight verified).
        $stmt = $this->pdo->prepare(
            'SELECT beer AS cool_beer, batch AS cool_batch,
                    final_volume AS cool_final_volume_hl, DATE(submitted_at) AS event_date,
                    recipe_id_fk AS cool_recipe_id
             FROM bd_brewing_gravity_v2
             WHERE event_type = "Cooling"
               AND DATE(submitted_at) >= :start
               AND final_volume > 0'
        );
        $stmt->execute([':start' => $simStart->format('Y-m-d')]);

        $events = [];
        foreach ($stmt->fetchAll() as $row) {
            $rawBeer  = trim($row['cool_beer'] ?? '');
            $beer     = $this->normalizeBeerName($rawBeer);
            $batch    = trim($row['cool_batch'] ?? '');
            $vol      = (float)($row['cool_final_volume_hl'] ?? 0);
            $date     = $this->parseDate($row['event_date'] ?? '');
            $recipeId = isset($row['cool_recipe_id']) ? (int)$row['cool_recipe_id'] : null;

            if ($beer === '' || $batch === '' || $date === null || $vol <= 0) continue;
            if ($this->isWortSale($beer)) continue;

            // Log unresolved recipe_id edges so they surface in error_log without
            // breaking the sim — fallback to beer-name-only keying is safe here.
            if ($recipeId === null) {
                error_log(sprintf(
                    '[TankSimulator] COOLING event with NULL recipe_id_fk: beer=%s batch=%s date=%s',
                    $beer, $batch, $date->format('Y-m-d')
                ));
            }

            $key           = $beer . '|' . $batch;
            $batchCCTEntry = $batchCCT[$key] ?? null;
            $cct           = $batchCCTEntry !== null ? $batchCCTEntry['cct'] : '?';
            $isRacked      = isset($rackedBatches[$key]);
            $isRecent      = $date >= $recentCutoff;

            // Part C fix (2026-06-04): do NOT drop an un-racked cooling event on recency
            // alone. An un-racked batch is physically still in its CCT regardless of age
            // (example: DIB/6 cooled 2026-02-06, un-racked, still in CCT9 at 96.7 HL).
            // Keep alive until: (a) it is racked (implicit-evict in RACKING case handles that),
            // (b) its CCT is reused by a later brew (COOLING implicit-evict), or (c) it
            // exceeds the MAX_TANK_AGE_DAYS cap (180 days). The 3-month $isRecent window
            // is kept as-is for racked events — those only matter for CCT eviction, and the
            // racking events themselves carry the date that actually clears the slot.
            //
            // Original rule: drop only when !isRacked && !isRecent.
            // New rule: additionally allow !isRacked && !isRecent when still within MAX_TANK_AGE_DAYS.
            if (!$isRacked && !$isRecent) {
                // Un-racked + beyond 3-month recency: keep if within MAX_TANK_AGE_DAYS (180d).
                if ($this->isExpired($date, new DateTimeImmutable('today'))) continue;
                // else: un-racked, old (>3mo), but within 180 days → keep (may be physically present)
            }
            // All other cases (racked, or recent regardless of racked) → keep unchanged.

            $events[] = [
                'type'          => 'COOLING',
                'date'          => $date,
                'beer'          => $beer,
                'raw_beer'      => $rawBeer,
                'batch'         => $batch,
                'recipe_id'     => $recipeId,
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
        // bd_racking → bd_racking_v2. The leftover-blend signal (legacy blend_text /
        // BSF col Q) is now the typed DECIMAL column blend_hl. The destination-tank
        // text (legacy bbt / "BBT 7") is now target_tank_raw; bbt_old is the legacy
        // int fallback. neb_* columns are NOT NULL DEFAULT '' in v2, so NULLIF the
        // empties before falling through to contract_*.
        // Date strategy (updated 2026-06-04): use event_date as the canonical racking date.
        // bd_racking_v2.start_time is populated by the web form's "Heure de début" field
        // but historically suffered a DD/MM↔MM/DD swap in the BSF-era ingest (same bug fixed
        // for packaging in commits c97cedd/f828ca4). event_date is the operator-entered date
        // (the *physical* date the racking happened) and is correct on 100% of rows.
        // Fallback chain: event_date → submitted_at → start_time (last resort, legacy only).
        $rows = $this->pdo->query(
            'SELECT COALESCE(NULLIF(neb_beer, ""), contract_beer)                    AS beer,
                    COALESCE(NULLIF(neb_batch, ""), contract_batch)                   AS batch,
                    COALESCE(neb_recipe_id_fk, contract_recipe_id_fk)                 AS recipe_id_fk,
                    target_tank_raw                                                    AS bbt,
                    bbt_old,
                    racked_vol_hl,
                    blend_hl                                                           AS blend_text,
                    loss_source_hl,
                    loss_dest_hl,
                    interrupted_flag,
                    dest_bbt_still_clean,
                    COALESCE(event_date, DATE(submitted_at), DATE(start_time))         AS rack_date
             FROM bd_racking_v2
             WHERE COALESCE(event_date, submitted_at, start_time) IS NOT NULL'
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

            $key           = $beer . '|' . $batch;
            $batchCCTEntry = $batchCCT[$key] ?? null;
            $cct           = $batchCCTEntry !== null ? $batchCCTEntry['cct'] : '?';
            $recipeId      = isset($row['recipe_id_fk']) ? (int)$row['recipe_id_fk'] : null;
            // Fallback to batchCCT recipe_id when the racking row has NULL.
            // 99.8% of racking rows have recipe_id_fk set (pre-flight verified).
            if ($recipeId === null && $batchCCTEntry !== null) {
                $recipeId = $batchCCTEntry['recipe_id'];
            }

            // C3 — carry loss columns on the event.
            // C4 — carry interrupted_flag + dest_bbt_still_clean on the event.
            $lossSourceHl = isset($row['loss_source_hl']) && $row['loss_source_hl'] !== null
                ? (float)$row['loss_source_hl'] : 0.0;
            $lossDestHl = isset($row['loss_dest_hl']) && $row['loss_dest_hl'] !== null
                ? (float)$row['loss_dest_hl'] : 0.0;
            $interruptedFlag = (int)($row['interrupted_flag'] ?? 0);
            $destBbtStillClean = isset($row['dest_bbt_still_clean']) && $row['dest_bbt_still_clean'] !== null
                ? (int)$row['dest_bbt_still_clean'] : null;

            $events[] = [
                'type'               => 'RACKING',
                'date'               => $date,
                'beer'               => $beer,
                'batch'              => $batch,
                'recipe_id'          => $recipeId,
                'cct'                => $cct,
                'bbt'                => (string)$bbt,
                'racked_vol'         => $rackedVol,
                'blend_vol'          => $blendVol,
                'loss_source_hl'     => $lossSourceHl,
                'loss_dest_hl'       => $lossDestHl,
                'interrupted_flag'   => $interruptedFlag,
                'dest_bbt_still_clean' => $destBbtStillClean,
                'sort_priority'      => 0,
            ];
        }
        return $events;
    }

    /** Build PACKAGING events. */
    private function loadPackagingEvents(): array
    {
        // SOURCE STRATEGY (2026-05-25, updated 2026-06-04):
        //
        // bd_packaging_v2 is now the sole packaging source. Every row is a distinct packaging
        // event (operator-confirmed: multiple same-day Cuv rows are distinct
        // serving-tank fills, not duplicates). No cross-source dedup needed.
        //
        // Key changes (2026-06-04):
        //   1. recipe_id_fk added — enables (recipe_id, batch) keying in the PACKAGING
        //      case below (Part B). neb_beer/contract_beer often hold SKU codes like
        //      STI4/SPYB/EMB4C which normalizeBeerName() passes through unchanged, so
        //      the old (beer|batch) key silently mismatched the racking entry. recipe_id_fk
        //      is 100% populated on drainable rows and matches neb_recipe_id_fk on racking.
        //   2. event_date used for the event date instead of submitted_at. The sim sorts
        //      events chronologically; racking already uses event_date; using submitted_at
        //      here let stale fills survive when submission lag placed packaging events
        //      after a newer racking in sort order (e.g. BBT2/BBT5 wrong-beer survivals).
        //   3. Deduct amount = vendable_hl + loss_kpi_hl (liquid losses already expressed
        //      in HL on the row). loss_kpi_hl captures keg-liquid-loss (loss_keg_liquid_l/100)
        //      and bottle/can unsaleable waste (unsaleable_units × per-unit HL). Operator
        //      note: "expect a few HL discrepancy — half-filled is computed differently in
        //      v2." Goal is correct beer identity + volumes within a few HL, not exact parity.

        // Load web-form rows from bd_packaging_v2.
        // Predicate: vendable_hl > 0 OR loss_kpi_hl > 0 — broadened from pure vendable > 0
        // to include pure-loss rows (vendable=0, loss_kpi>0) such as cuv burst-bag events
        // where beer physically left the tank but nothing was vendable.
        // Normal rows (vendable>0) are unaffected — drain = vendable + loss_kpi, unchanged.
        // PHP guard below (vendableHl + lossKpiHl <= 0) prevents negative-drain rows from
        // being added as events (e.g. rows where loss_kpi > 0 but vendable is so negative
        // that their sum is still ≤ 0).
        $v2Rows = $this->pdo->query(
            'SELECT COALESCE(NULLIF(neb_beer, ""), contract_beer) AS beer,
                    COALESCE(NULLIF(neb_batch, ""), contract_batch) AS batch,
                    recipe_id_fk,
                    vendable_hl,
                    COALESCE(loss_kpi_hl, 0) AS loss_kpi_hl,
                    event_date
             FROM bd_packaging_v2
             WHERE event_date IS NOT NULL
               AND (
                     (vendable_hl IS NOT NULL AND CAST(vendable_hl AS DECIMAL(14,4)) > 0)
                  OR (loss_kpi_hl IS NOT NULL AND CAST(loss_kpi_hl AS DECIMAL(14,4)) > 0)
               )
               AND is_tombstoned = 0
               AND reuses_packaging_id_fk IS NULL'
        )->fetchAll();

        // Parse v2 rows into canonical event structure.
        // neb_beer/contract_beer hold racking-style names or SKU codes — normalizeBeerName()
        // normalises the canonical names but passes SKU codes through unchanged. The beer
        // field is kept for fallback display/guard purposes; recipe_id is the primary key.
        $events = [];

        $processRow = function (array $row) use (&$events): void {
            $beer        = $this->normalizeBeerName(trim($row['beer'] ?? ''));
            $batch       = trim($row['batch'] ?? '');
            $vendableHl  = (float)($row['vendable_hl'] ?? 0);
            $lossKpiHl   = (float)($row['loss_kpi_hl'] ?? 0);
            $date        = $this->parseDate($row['event_date'] ?? '');
            $recipeId    = isset($row['recipe_id_fk']) && $row['recipe_id_fk'] !== null
                ? (int)$row['recipe_id_fk'] : null;

            // Guard: skip if total liquid leaving the tank is zero or negative.
            // This catches rows that passed the SQL OR predicate (e.g. loss_kpi_hl > 0)
            // but whose vendable + loss sum is still non-positive (e.g. a can row with a
            // large loss_liquid_other_units making vendable hugely negative).
            if ($beer === '' || $date === null || ($vendableHl + $lossKpiHl) <= 0) return;
            if ($this->isWortSale($beer)) return;

            $events[] = [
                'type'          => 'PACKAGING',
                'date'          => $date,
                'beer'          => $beer,
                'batch'         => $batch,
                'recipe_id'     => $recipeId,
                // Total liquid leaving the tank: vendable + liquid losses (loss_kpi_hl).
                // The flat packagingLossHl constant (0.15 HL per run from commissioning_settings)
                // is added in the PACKAGING case processor — do not include it here.
                'hl'            => $vendableHl + $lossKpiHl,
                'sort_priority' => 2,
            ];
        };

        foreach ($v2Rows as $row) { $processRow($row); }

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

    /** Reverse lookup: canonical → raw operator name (or canonical if no mapping exists). */
    public static function rawBeerName(string $canonical): string
    {
        static $reverse = null;
        if ($reverse === null) {
            $reverse = array_flip(self::BEER_NAME_MAP);
        }
        return $reverse[$canonical] ?? $canonical;
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
     * Reduce every lot in $blendInfo proportionally so that the lot volumes
     * continue to sum to (previous_total − $drawHl) after a draw-down.
     *
     * Invariant preserved: sum(lots[].vol) == new BBT volume_hl.
     *
     * Safe-guards:
     *   - $previousTotalHl == 0  → clear all lots (avoids divide-by-zero).
     *   - $drawHl >= $previousTotalHl → empty result (every lot goes to 0).
     *   - Individual lot scaled below a float-noise floor (1e-6 HL) → zeroed
     *     and omitted from the result so stale zero-vol lots don't accumulate.
     *
     * Called by: PACKAGING draw-down, deferred-deduction application.
     * Will be reused by: WS1 destination-loss apportionment (per-lot racking loss).
     *
     * @param array $blendInfo  Array of ['batch'=>string, 'vol'=>float] entries.
     *                          Passed by reference; replaced with the scaled result.
     * @param float $previousTotalHl  BBT volume_hl BEFORE the draw (used as denominator).
     * @param float $drawHl     HL to remove (packaging vol + loss).
     */
    private function _bbt_prorata_decrement(
        array &$blendInfo,
        float  $previousTotalHl,
        float  $drawHl
    ): void {
        // Nothing to scale — clear everything.
        if ($previousTotalHl <= 0.0 || $drawHl >= $previousTotalHl) {
            $blendInfo = [];
            return;
        }

        $scale    = ($previousTotalHl - $drawHl) / $previousTotalHl;
        $newBlend = [];
        foreach ($blendInfo as $bi) {
            $newVol = $bi['vol'] * $scale;
            if ($newVol > 1e-6) {
                $newBlend[] = ['batch' => $bi['batch'], 'vol' => $newVol];
            }
        }
        $blendInfo = $newBlend;
    }

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

    /**
     * Returns the BBT-empty threshold (HL) loaded from commissioning_settings,
     * falling back to the class-const default when the DB row is absent.
     * Exposed so callers (e.g. form-racking.php) can pass it to
     * tank_bbt_composition() without re-reading the DB.
     */
    public function bbtEmptyThresholdHl(): float
    {
        return $this->bbtEmptyThresholdHl;
    }
}

// ─────────────────────────────────────────────────────────────────────────────
// Read API — per-BBT lot composition
// ─────────────────────────────────────────────────────────────────────────────

/**
 * Derive the lot-level composition of every non-empty BBT from the simulator's
 * final state.  Pure read — no recompute, no DB access.
 *
 * Callers:
 *   $sim   = new TankSimulator($pdo);
 *   $state = $sim->run();
 *   $comp  = tank_bbt_composition($state);
 *
 * Contract: does NOT modify $simState; the existing ->run() return shape is
 * unchanged — this is an additive read API, not a replacement.
 *
 * @param array $simState  Return value of TankSimulator::run().
 * @return array  Indexed array, one entry per occupied BBT (sorted by bbt ASC):
 *   [
 *     'bbt'      => int,          // tank number
 *     'beer'     => string,       // canonical beer name
 *     'total_hl' => float,        // volume_hl of this BBT
 *     'lots'     => [             // one entry per lot in blend_info
 *       ['batch' => string, 'vol_hl' => float, 'pct' => float],
 *       ...
 *     ],
 *   ]
 *   Empty if no BBT is occupied or blend_info is absent.
 */
function tank_bbt_composition(array $simState, float $emptyThresholdHl = 2.5): array
{
    $result = [];

    foreach ($simState['bbt'] ?? [] as $bbtNum => $state) {
        if ($state === null || empty($state['blend_info'])) {
            continue;
        }

        $totalHl = (float)($state['volume_hl'] ?? 0.0);
        // Gate: omit sub-threshold BBTs from blend candidates.
        // A tank below the dead-volume floor has nothing useful to blend with.
        if ($totalHl < $emptyThresholdHl) {
            continue;
        }
        $lots    = [];

        foreach ($state['blend_info'] as $bi) {
            $volHl = (float)($bi['vol'] ?? 0.0);
            $pct   = $totalHl > 0.0
                ? round($volHl / $totalHl * 100.0, 1)
                : 0.0;
            $lots[] = [
                'batch'  => (string)($bi['batch'] ?? ''),
                'vol_hl' => $volHl,
                'pct'    => $pct,
            ];
        }

        $result[] = [
            'bbt'      => (int)$bbtNum,
            'beer'     => (string)($state['beer'] ?? ''),
            'total_hl' => $totalHl,
            'lots'     => $lots,
        ];
    }

    // Sort by BBT number ascending for deterministic output.
    usort($result, fn($a, $b) => $a['bbt'] <=> $b['bbt']);

    return $result;
}
