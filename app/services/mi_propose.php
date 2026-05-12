<?php
declare(strict_types=1);

/**
 * proposeMi — infer an MI for a raw invoice line.
 *
 * Returns a proposal array. All proposed_* fields are null when confidence
 * is below 0.50 — the operator gets a blank form but still sees reasoning
 * and the similar-MI list as debug aids.
 *
 * Confidence weighting rationale:
 *   0.40 supplier-category strength — strongest single signal; a supplier
 *        that overwhelmingly sells Packaging tells us the category immediately.
 *   0.30 subcategory keyword match — within-category disambiguation; critical
 *        for multi-subcat categories like Packaging (Bottle/Box/Can/Label/…).
 *   0.20 variant extraction — size + color pins the ID suffix; without it the
 *        ID is generic and will collide with the wrong existing MI.
 *   0.10 modal-account dominance — usually just confirms what cat/subcat
 *        already implied; tiny weight to avoid over-rewarding trivial cases.
 */

/**
 * @param string   $rawLine    Original invoice line text.
 * @param int|null $supplierId ref_suppliers.id, or null when unknown.
 * @param PDO      $pdo        Caller-provided connection (no static inside service).
 * @return array{
 *   proposed_mi_id: string|null,
 *   proposed_category: string|null,
 *   proposed_subcategory: string|null,
 *   proposed_account: string|null,
 *   proposed_name: string|null,
 *   proposition_confidence: float,
 *   similar_mi_ids: list<string>,
 *   reasoning: list<string>
 * }
 */
function proposeMi(string $rawLine, ?int $supplierId, PDO $pdo): array
{
    $reasoning = [];
    $line      = _miNormToken($rawLine);

    // ── Step 1: supplier modal category ──────────────────────────────────────
    // 0.40 weight; score = modal_count / total_deliveries_for_supplier.
    // Capped at 1.0 — a supplier selling only Packaging is max signal.
    $catName   = null;
    $catScore  = 0.0;

    if ($supplierId !== null) {
        $stmt = $pdo->prepare(
            "SELECT c.name AS category, COUNT(*) AS n
               FROM inv_deliveries d
               JOIN ref_mi m           ON d.ingredient_fk = m.id
               JOIN ref_mi_categories c ON c.id = m.category_id
              WHERE d.supplier_fk = ? AND m.is_active = 1
              GROUP BY c.name
              ORDER BY n DESC"
        );
        $stmt->execute([$supplierId]);
        $catRows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        if (!empty($catRows)) {
            $total  = (int) array_sum(array_column($catRows, 'n'));
            $modal  = (int) $catRows[0]['n'];
            $catName  = $catRows[0]['category'];
            $catScore = $total > 0 ? min(1.0, $modal / $total) : 0.0;
            $reasoning[] = sprintf(
                "step1: supplier modal_cat=%s (%d/%d = %.2f)",
                $catName, $modal, $total, $catScore
            );
        } else {
            $reasoning[] = "step1: supplier has no delivery history — cat=null";
        }
    } else {
        $reasoning[] = "step1: supplierId=null — no category signal";
    }

    // ── Step 2: subcategory keyword match ────────────────────────────────────
    // Tokenize the raw line; score each subcategory under the modal category
    // by the fraction of its representative name-tokens that appear in $line.
    // 0.30 weight.
    $subcatName  = null;
    $subcatScore = 0.0;

    if ($catName !== null) {
        // Pull all distinct (subcat_name, mi_name) pairs under this category.
        $stmt2 = $pdo->prepare(
            "SELECT s.name AS subcat, m.name AS mi_name
               FROM ref_mi m
               JOIN ref_mi_categories c ON c.id = m.category_id
               LEFT JOIN ref_mi_subcategories s ON s.id = m.subcategory_id
              WHERE c.name = ? AND m.is_active = 1"
        );
        $stmt2->execute([$catName]);
        $miRows = $stmt2->fetchAll(PDO::FETCH_ASSOC);

        // Build per-subcat token bank from all MI names in that subcat.
        $subcatTokens = [];  // subcat => flat list of tokens
        foreach ($miRows as $row) {
            $sc = $row['subcat'] ?? '';
            if ($sc === '') continue;
            $toks = _miTokenize($row['mi_name']);
            foreach ($toks as $t) {
                $subcatTokens[$sc][] = $t;
            }
        }

        $bestSubcat = null;
        $bestScore  = 0.0;
        foreach ($subcatTokens as $sc => $toks) {
            $unique = array_unique($toks);
            $hits   = 0;
            foreach ($unique as $t) {
                if (str_contains($line, $t)) {
                    $hits++;
                }
            }
            $score = count($unique) > 0 ? $hits / count($unique) : 0.0;
            if ($score > $bestScore) {
                $bestScore  = $score;
                $bestSubcat = $sc;
            }
        }

        if ($bestSubcat !== null && $bestScore > 0.0) {
            $subcatName  = $bestSubcat;
            $subcatScore = $bestScore;
            $reasoning[] = sprintf("step2: subcat=%s score=%.2f", $subcatName, $subcatScore);
        } else {
            $reasoning[] = "step2: no keyword match for any subcat under $catName";
        }
    } else {
        $reasoning[] = "step2: skipped (no category)";
    }

    // ── Step 3: variant extraction ───────────────────────────────────────────
    // Extract size tokens, color tokens, grade qualifiers.
    // variantScore = (found_parts / 3): size, color, grade each count as 1/3.
    $sizeToken    = null;
    $colorToken   = null;
    $gradeToken   = null;
    $variantScore = 0.0;

    // Size: digits followed by unit — capture as NNNcl, NNNml, NNNl, NNNkg, NNNg, NNNhl
    if (preg_match('/(\d+(?:[.,]\d+)?)\s*(cl|ml|hl|kg|g\b|l\b)/i', $rawLine, $m)) {
        $qty  = str_replace(',', '.', $m[1]);
        $unit = strtolower($m[2]);
        // Normalize: keep as written (e.g. 33cl, 5kg, 3464kg)
        $sizeToken = $qty . $unit;
        $variantScore += 1 / 3;
        $reasoning[] = "step3: size=$sizeToken";
    }

    // Color: amber/ambre, brun/brown, vert/green, blanc/clear/white
    $colorMap = [
        'amber' => 'amber', 'ambre' => 'amber',
        'brun'  => 'brun',  'brown' => 'brun',
        'vert'  => 'vert',  'green' => 'vert',
        'blanc' => 'blanc', 'white' => 'blanc', 'clear' => 'blanc',
    ];
    foreach ($colorMap as $token => $canonical) {
        if (str_contains($line, $token)) {
            $colorToken = $canonical;
            $variantScore += 1 / 3;
            $reasoning[] = "step3: color=$colorToken";
            break;
        }
    }

    // Grade: T-90, T90, pellets, vrac, bulk, cryo, incognito, liquid
    $gradeMap = [
        't-90' => 'T90', 't90' => 'T90',
        'pellets' => 'PELLETS',
        'vrac' => 'VRAC', 'bulk' => 'VRAC',
        'cryo' => 'CRYO', 'incognito' => 'INCOGNITO',
        'liquid' => 'LIQ', 'liquide' => 'LIQ',
    ];
    foreach ($gradeMap as $token => $canonical) {
        if (str_contains($line, $token)) {
            $gradeToken = $canonical;
            $variantScore += 1 / 3;
            $reasoning[] = "step3: grade=$gradeToken";
            break;
        }
    }

    if ($sizeToken === null && $colorToken === null && $gradeToken === null) {
        $reasoning[] = "step3: no variant tokens found";
    }

    // ── Step 4: ID composition ────────────────────────────────────────────────
    // Derive prefix from existing MIs in the same (cat, subcat) via longest
    // common prefix of their IDs. Never hardcode prefix tables.
    $proposedId     = null;
    $proposedIdBase = null;

    if ($catName !== null) {
        $stmt3 = $pdo->prepare(
            "SELECT m.mi_id
               FROM ref_mi m
               JOIN ref_mi_categories c ON c.id = m.category_id
               LEFT JOIN ref_mi_subcategories s ON s.id = m.subcategory_id
              WHERE c.name = ?
                AND (? IS NULL OR s.name = ?)
                AND m.is_active = 1
              ORDER BY m.mi_id"
        );
        $stmt3->execute([$catName, $subcatName, $subcatName]);
        $existingIds = $stmt3->fetchAll(PDO::FETCH_COLUMN);

        $prefix = _lcpPrefix($existingIds);

        // Build suffix from variant tokens: SIZE_COLOR or SIZE_GRADE etc.
        $suffix = implode('_', array_filter([
            $sizeToken  !== null ? strtoupper(str_replace('.', '', $sizeToken)) : null,
            $colorToken !== null ? strtoupper($colorToken) : null,
            $gradeToken !== null ? strtoupper($gradeToken) : null,
        ]));

        if ($suffix === '') {
            // Fall back to first meaningful word from line as suffix discriminator
            $words = explode(' ', preg_replace('/[^a-z0-9 ]/i', ' ', $rawLine));
            $words = array_filter($words, fn($w) => strlen($w) >= 3);
            $suffix = strtoupper(reset($words) ?: 'TBD');
        }

        $proposedIdBase = rtrim($prefix, '_') . '_' . $suffix;
        $reasoning[]    = sprintf("step4: prefix=%s suffix=%s → base=%s", $prefix, $suffix, $proposedIdBase);
    } else {
        $reasoning[] = "step4: skipped (no category)";
    }

    // ── Step 5: modal GL account ──────────────────────────────────────────────
    // 0.10 weight; score = modal_count / total MIs in (cat, subcat).
    $proposedAccount  = null;
    $accountScore     = 0.0;

    if ($catName !== null) {
        $stmt4 = $pdo->prepare(
            "SELECT s.gl_account AS account, COUNT(*) AS n
               FROM ref_mi m
               JOIN ref_mi_categories c ON c.id = m.category_id
               LEFT JOIN ref_mi_subcategories s ON s.id = m.subcategory_id
              WHERE c.name = ?
                AND (? IS NULL OR s.name = ?)
                AND m.is_active = 1
                AND s.gl_account IS NOT NULL
              GROUP BY s.gl_account
              ORDER BY n DESC
              LIMIT 1"
        );
        $stmt4->execute([$catName, $subcatName, $subcatName]);
        $acctRow = $stmt4->fetch(PDO::FETCH_ASSOC);

        if ($acctRow) {
            // Get total for dominance ratio
            $stmtTotal = $pdo->prepare(
                "SELECT COUNT(*) AS n
                   FROM ref_mi m
                   JOIN ref_mi_categories c ON c.id = m.category_id
                   LEFT JOIN ref_mi_subcategories s ON s.id = m.subcategory_id
                  WHERE c.name = ?
                    AND (? IS NULL OR s.name = ?)
                    AND m.is_active = 1"
            );
            $stmtTotal->execute([$catName, $subcatName, $subcatName]);
            $total = (int) $stmtTotal->fetchColumn();

            $proposedAccount = $acctRow['account'];
            $accountScore    = $total > 0 ? min(1.0, (int)$acctRow['n'] / $total) : 0.0;
            $reasoning[]     = sprintf(
                "step5: account=%s dominance=%.2f",
                $proposedAccount, $accountScore
            );
        } else {
            // Subcategory GL not set — fall back to category default
            $stmtCatAcct = $pdo->prepare(
                "SELECT default_gl_account FROM ref_mi_categories WHERE name = ?"
            );
            $stmtCatAcct->execute([$catName]);
            $defAcct = $stmtCatAcct->fetchColumn();
            if ($defAcct) {
                $proposedAccount = (string) $defAcct;
                $accountScore    = 0.3;   // partial signal — category default only
                $reasoning[]     = "step5: account={$proposedAccount} (category default fallback)";
            } else {
                $reasoning[] = "step5: no account found for cat=$catName subcat=$subcatName";
            }
        }
    } else {
        $reasoning[] = "step5: skipped (no category)";
    }

    // ── Step 6: similar MIs by Levenshtein ───────────────────────────────────
    // Top 3 by edit distance of proposed base ID to existing IDs in same cat/subcat.
    // When subcat_confidence < 0.30, expand scope to full category (subcat may have
    // misfired — e.g. "Citra T-90" matching "Special" instead of "Aroma" because
    // T-90 token hits Special keyword bank better). Category is usually right even
    // when subcat is wrong, so the full-cat scan rescues the obvious match.
    $similarIds = [];

    if ($proposedIdBase !== null && $catName !== null) {
        $expandToCategory = ($subcatScore < 0.30);
        if ($expandToCategory) {
            $reasoning[] = "step6: subcat_confidence=" . round($subcatScore, 2) . " < 0.30 — expanding similar-MI scan to full category scope";
        }

        if ($expandToCategory) {
            $stmt5 = $pdo->prepare(
                "SELECT m.mi_id
                   FROM ref_mi m
                   JOIN ref_mi_categories c ON c.id = m.category_id
                  WHERE c.name = ?
                    AND m.is_active = 1"
            );
            $stmt5->execute([$catName]);
        } else {
            $stmt5 = $pdo->prepare(
                "SELECT m.mi_id
                   FROM ref_mi m
                   JOIN ref_mi_categories c ON c.id = m.category_id
                   LEFT JOIN ref_mi_subcategories s ON s.id = m.subcategory_id
                  WHERE c.name = ?
                    AND (? IS NULL OR s.name = ?)
                    AND m.is_active = 1"
            );
            $stmt5->execute([$catName, $subcatName, $subcatName]);
        }
        $candIds = $stmt5->fetchAll(PDO::FETCH_COLUMN);

        $dists = [];
        foreach ($candIds as $cid) {
            $dists[$cid] = levenshtein(strtolower($proposedIdBase), strtolower($cid));
        }
        asort($dists);
        $similarIds = array_slice(array_keys($dists), 0, 3);
        $reasoning[] = "step6: similar=" . implode(', ', $similarIds);
    } else {
        $reasoning[] = "step6: skipped (no proposedIdBase or category)";
    }

    // ── Step 7: composite confidence ─────────────────────────────────────────
    $confidence = (0.40 * $catScore)
                + (0.30 * $subcatScore)
                + (0.20 * $variantScore)
                + (0.10 * $accountScore);
    $reasoning[] = sprintf(
        "step7: confidence=%.3f (cat=%.2f×0.40 subcat=%.2f×0.30 variant=%.2f×0.20 acct=%.2f×0.10)",
        $confidence, $catScore, $subcatScore, $variantScore, $accountScore
    );

    // ── Step 8: collision handling ───────────────────────────────────────────
    $proposedId = $proposedIdBase;
    if ($proposedId !== null) {
        $stmtChk = $pdo->prepare("SELECT 1 FROM ref_mi WHERE mi_id = ? LIMIT 1");
        $suffix   = 2;
        $base     = $proposedId;
        while (true) {
            $stmtChk->execute([$proposedId]);
            if (!$stmtChk->fetchColumn()) break;
            $reasoning[] = "step8: collision on $proposedId — trying suffix _$suffix";
            $proposedId  = $base . '_' . $suffix;
            $suffix++;
        }
        if ($proposedId !== $base) {
            $reasoning[] = "step8: resolved to $proposedId";
        } else {
            $reasoning[] = "step8: no collision";
        }
    }

    // ── Step 9: name derivation ───────────────────────────────────────────────
    // Heuristic prettification: size canonical + color/grade + category suffix.
    $proposedName = null;
    if ($catName !== null && $proposedIdBase !== null) {
        $parts = [];
        // Category-aware noun prefix
        $nounMap = [
            'Packaging/Bottle'           => 'Bouteille',
            'Packaging/Box'              => 'Carton',
            'Packaging/Can'              => 'Canette',
            'Packaging/Label'            => 'Label',
            'Packaging/Liner'            => 'Liner',
            'Packaging/Pack'             => 'Pack',
            'Packaging/Keg'              => 'Fût',
            'Hops/Aroma'                 => '',
            'Hops/Bittering'             => '',
            'Hops/Special'               => '',
            'Malt/Base'                  => '',
            'Malt/Specialty'             => '',
            'Malt/Adjunct'               => '',
            'Process Chemical/Gas'       => '',
        ];
        $nounKey  = "$catName/" . ($subcatName ?? '');
        $noun     = $nounMap[$nounKey] ?? '';

        if ($sizeToken !== null) $parts[] = ucfirst($sizeToken);
        if ($colorToken !== null) {
            $colorLabels = [
                'amber' => 'ambre', 'brun' => 'brun',
                'vert' => 'vert', 'blanc' => 'blanc',
            ];
            $parts[] = $colorLabels[$colorToken] ?? $colorToken;
        }
        if ($gradeToken !== null) {
            $gradeLabels = [
                'T90' => 'T-90', 'PELLETS' => 'pellets',
                'VRAC' => 'vrac', 'CRYO' => 'Cryo',
                'INCOGNITO' => 'Incognito', 'LIQ' => 'Liquide',
            ];
            $parts[] = $gradeLabels[$gradeToken] ?? $gradeToken;
        }

        // Fall back to tokenizing the raw line if nothing else
        if (empty($parts)) {
            // Capitalize words longer than 2 chars, skip known noise
            $noise = ['de', 'la', 'le', 'les', 'du', 'des', 'en', 'pour', 'et'];
            $raw   = array_filter(
                explode(' ', $rawLine),
                fn($w) => strlen($w) > 2 && !in_array(strtolower($w), $noise, true)
            );
            $parts = array_map('ucfirst', array_slice(array_values($raw), 0, 4));
        }

        $proposedName = trim(($noun !== '' ? $noun . ' ' : '') . implode(' ', $parts));
        $reasoning[]  = "step9: name=\"$proposedName\"";
    } else {
        $reasoning[] = "step9: skipped (no category or id)";
    }

    // ── Assemble result ───────────────────────────────────────────────────────
    $belowThreshold = $confidence < 0.50;
    if ($belowThreshold) {
        $reasoning[] = sprintf("result: confidence=%.3f < 0.50 — returning nulls for proposed fields", $confidence);
    }

    return [
        'proposed_mi_id'         => $belowThreshold ? null : $proposedId,
        'proposed_category'      => $belowThreshold ? null : $catName,
        'proposed_subcategory'   => $belowThreshold ? null : $subcatName,
        'proposed_account'       => $belowThreshold ? null : $proposedAccount,
        'proposed_name'          => $belowThreshold ? null : $proposedName,
        'proposition_confidence' => round($confidence, 3),
        'similar_mi_ids'         => $similarIds,
        'reasoning'              => $reasoning,
    ];
}

// ── Internal helpers ─────────────────────────────────────────────────────────

/**
 * Tokenize a name for keyword matching: lowercase, strip accents, split on
 * non-word characters, drop tokens shorter than 3 chars (noise like 'de').
 *
 * @return list<string>
 */
function _miTokenize(string $text): array
{
    $text = _miNormToken($text);
    $toks = preg_split('/[^a-z0-9]+/', $text, -1, PREG_SPLIT_NO_EMPTY);
    return array_values(array_filter($toks, fn($t) => strlen($t) >= 3));
}

/**
 * Normalize for token matching: lowercase + strip accents.
 */
function _miNormToken(string $text): string
{
    $text = mb_strtolower($text, 'UTF-8');
    // Transliterate accented chars: é→e, ü→u, etc.
    $map = [
        'à'=>'a','á'=>'a','â'=>'a','ä'=>'a','ã'=>'a',
        'è'=>'e','é'=>'e','ê'=>'e','ë'=>'e',
        'ì'=>'i','í'=>'i','î'=>'i','ï'=>'i',
        'ò'=>'o','ó'=>'o','ô'=>'o','ö'=>'o','õ'=>'o',
        'ù'=>'u','ú'=>'u','û'=>'u','ü'=>'u',
        'ý'=>'y','ÿ'=>'y','ñ'=>'n','ç'=>'c','ß'=>'ss',
    ];
    return strtr($text, $map);
}

/**
 * Longest common prefix among an array of strings.
 * Returns empty string if array is empty or has only 1 element that is itself
 * a bare word with no underscore.
 *
 * For MI IDs like PKG_BOT_PIVO, PKG_BOT_VICHY → prefix = PKG_BOT_
 * Used to derive the structural prefix for a proposed ID without hardcoding.
 *
 * @param list<string> $ids
 */
function _lcpPrefix(array $ids): string
{
    if (empty($ids)) return '';
    if (count($ids) === 1) {
        // Single example: return everything up to and including the last underscore.
        $pos = strrpos($ids[0], '_');
        return $pos !== false ? substr($ids[0], 0, $pos + 1) : '';
    }

    $first = $ids[0];
    $len   = strlen($first);
    $lcp   = '';
    for ($i = 0; $i < $len; $i++) {
        $char = $first[$i];
        foreach ($ids as $id) {
            if (!isset($id[$i]) || $id[$i] !== $char) {
                // Trim back to last underscore so we get a clean segment boundary.
                $pos = strrpos($lcp, '_');
                return $pos !== false ? substr($lcp, 0, $pos + 1) : $lcp;
            }
        }
        $lcp .= $char;
    }

    return $lcp;
}
