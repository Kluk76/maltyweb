-- db/migrations/065_populate_ref_recipe_aliases.sql
-- What: Populate ref_recipe_aliases with the full canonical alias set discovered
--       from BSF!BeerTypes (col I = Aliases), lib/beer.js (_BEER_NAME_MAP + _SKU_BEER_MAP),
--       app/tank-simulator.php, and historical bd_fermenting.beers_to_read patterns.
-- Why:  Multiple datasets use different naming conventions (short codes, dotted abbreviations,
--       date-coded EPH variants, alternate canonical forms). The resolver needs a single
--       authoritative alias table so every dataset matches deterministically.
-- Risk: INSERT IGNORE on UNIQUE(alias) — safe re-run, no overwrites, no deletes.
--       ref_recipe_aliases already has UNIQUE KEY uniq_alias (alias) — no schema change needed.
-- Rollback: DELETE FROM ref_recipe_aliases WHERE notes LIKE '%migration 065%';

START TRANSACTION;

-- ──────────────────────────────────────────────────────────────────────────────
-- 1. Short-code (SKU prefix) aliases  — sources: lib/beer.js _SKU_BEER_MAP,
--    app/tank-simulator.php, BSF!BeerTypes col H (SkuCode)
-- ──────────────────────────────────────────────────────────────────────────────

-- ZEP → Zepp
INSERT IGNORE INTO ref_recipe_aliases (alias, recipe_id, notes)
SELECT 'ZEP', id, 'Short SKU prefix; source: lib/beer.js _SKU_BEER_MAP + BSF!BeerTypes!H58 + tank-simulator.php; migration 065'
FROM ref_recipes WHERE name = 'Zepp' AND vintage = '' LIMIT 1;

-- EMB → Embuscade
INSERT IGNORE INTO ref_recipe_aliases (alias, recipe_id, notes)
SELECT 'EMB', id, 'Short SKU prefix; source: lib/beer.js _SKU_BEER_MAP + BSF!BeerTypes!H33 + tank-simulator.php; migration 065'
FROM ref_recipes WHERE name = 'Embuscade' AND vintage = '' LIMIT 1;

-- MOO → Moonshine
INSERT IGNORE INTO ref_recipe_aliases (alias, recipe_id, notes)
SELECT 'MOO', id, 'Short SKU prefix; source: lib/beer.js _SKU_BEER_MAP + BSF!BeerTypes!H45 + tank-simulator.php; migration 065'
FROM ref_recipes WHERE name = 'Moonshine' AND vintage = '' LIMIT 1;

-- STI → Stirling
INSERT IGNORE INTO ref_recipe_aliases (alias, recipe_id, notes)
SELECT 'STI', id, 'Short SKU prefix; source: lib/beer.js _SKU_BEER_MAP + BSF!BeerTypes!H53 + tank-simulator.php; migration 065'
FROM ref_recipes WHERE name = 'Stirling' AND vintage = '' LIMIT 1;

-- SPY → Speakeasy
INSERT IGNORE INTO ref_recipe_aliases (alias, recipe_id, notes)
SELECT 'SPY', id, 'Short SKU prefix; source: lib/beer.js _SKU_BEER_MAP + BSF!BeerTypes!H52 + tank-simulator.php; migration 065'
FROM ref_recipes WHERE name = 'Speakeasy' AND vintage = '' LIMIT 1;

-- DIV → Diversion
INSERT IGNORE INTO ref_recipe_aliases (alias, recipe_id, notes)
SELECT 'DIV', id, 'Short SKU prefix; source: lib/beer.js _SKU_BEER_MAP + BSF!BeerTypes!H26 + tank-simulator.php; migration 065'
FROM ref_recipes WHERE name = 'Diversion' AND vintage = '' LIMIT 1;

-- DOA → Double Oat
INSERT IGNORE INTO ref_recipe_aliases (alias, recipe_id, notes)
SELECT 'DOA', id, 'Short SKU prefix; source: lib/beer.js _SKU_BEER_MAP + BSF!BeerTypes!H31 + tank-simulator.php; migration 065'
FROM ref_recipes WHERE name = 'Double Oat' AND vintage = '' LIMIT 1;

-- EST → Estafette
INSERT IGNORE INTO ref_recipe_aliases (alias, recipe_id, notes)
SELECT 'EST', id, 'Short SKU prefix; source: lib/beer.js _SKU_BEER_MAP + BSF!BeerTypes!H34 + tank-simulator.php; migration 065'
FROM ref_recipes WHERE name = 'Estafette' AND vintage = '' LIMIT 1;

-- ALT → Alternative
INSERT IGNORE INTO ref_recipe_aliases (alias, recipe_id, notes)
SELECT 'ALT', id, 'Short SKU prefix; source: lib/beer.js _SKU_BEER_MAP + BSF!BeerTypes!H7 + tank-simulator.php; migration 065'
FROM ref_recipes WHERE name = 'Alternative' AND vintage = '' LIMIT 1;

-- DIB → Diversion Blanche  (already in table as "Div.Blanche" → id 26; DIB is the SKU code form)
INSERT IGNORE INTO ref_recipe_aliases (alias, recipe_id, notes)
SELECT 'DIB', id, 'Short SKU prefix for Diversion Blanche; source: lib/beer.js _SKU_BEER_MAP + BSF!BeerTypes!H27 + tank-simulator.php; migration 065'
FROM ref_recipes WHERE name = 'Diversion Blanche' AND vintage = '' LIMIT 1;

-- DIG → Diversion Gose  (already has "Div.Gose" alias; DIG is the SKU code)
INSERT IGNORE INTO ref_recipe_aliases (alias, recipe_id, notes)
SELECT 'DIG', id, 'Short SKU prefix for Diversion Gose; source: lib/beer.js _SKU_BEER_MAP + BSF!BeerTypes!H28 + tank-simulator.php; migration 065'
FROM ref_recipes WHERE name = 'Diversion Gose' AND vintage = '' LIMIT 1;

-- DIP → Diversion Panaché  (already has "Div.Panaché" alias; DIP is the SKU code)
INSERT IGNORE INTO ref_recipe_aliases (alias, recipe_id, notes)
SELECT 'DIP', id, 'Short SKU prefix for Diversion Panaché; source: lib/beer.js _SKU_BEER_MAP + BSF!BeerTypes!H29 + tank-simulator.php; migration 065'
FROM ref_recipes WHERE name = 'Diversion Panaché' AND vintage = '' LIMIT 1;

-- DGD → DrunkBeard - Galactic Drift
INSERT IGNORE INTO ref_recipe_aliases (alias, recipe_id, notes)
SELECT 'DGD', id, 'Short SKU prefix; source: lib/beer.js _SKU_BEER_MAP + BSF!BeerTypes!H32 + tank-simulator.php + bd_fermenting.beer_dh; migration 065'
FROM ref_recipes WHERE name = 'DrunkBeard - Galactic Drift' AND vintage = '' LIMIT 1;

-- BLO → Blonde des Romands
INSERT IGNORE INTO ref_recipe_aliases (alias, recipe_id, notes)
SELECT 'BLO', id, 'Short SKU prefix; source: lib/beer.js _SKU_BEER_MAP + BSF!BeerTypes!H11 + bd_fermenting.beers_to_read (BLO 7-23); migration 065'
FROM ref_recipes WHERE name = 'Blonde des Romands' AND vintage = '' LIMIT 1;

-- BLA → Diversion Blanche (retired alternate prefix, see lib/beer.js RETIRED_CODES)
INSERT IGNORE INTO ref_recipe_aliases (alias, recipe_id, notes)
SELECT 'BLA', id, 'Retired alternate SKU prefix for Diversion Blanche; source: lib/beer.js _SKU_BEER_MAP (BLA→Div.Blanche); migration 065'
FROM ref_recipes WHERE name = 'Diversion Blanche' AND vintage = '' LIMIT 1;

-- DOC → Docks - NEIPA
INSERT IGNORE INTO ref_recipe_aliases (alias, recipe_id, notes)
SELECT 'DOC', id, 'Short SKU prefix for Docks - NEIPA; source: BSF!BeerTypes!H30 + lib/beer.js _SKU_BEER_MAP (DOC→Dockeuse); migration 065'
FROM ref_recipes WHERE name = 'Docks - NEIPA' AND vintage = '' LIMIT 1;

-- NYL → NYL  (ref_recipes already has name="NYL"; alias covers the "NYL (Hard Seltzer)" form from lib/beer.js)
INSERT IGNORE INTO ref_recipe_aliases (alias, recipe_id, notes)
SELECT 'NYL (Hard Seltzer)', id, 'Full form used in lib/beer.js BEER_TYPE_MAP; canonical in ref_recipes is plain "NYL"; source: lib/beer.js + tank-simulator.php; migration 065'
FROM ref_recipes WHERE name = 'NYL' AND vintage = '' LIMIT 1;

-- QDG → Qrew - Diversion Gose
-- First ensure the recipe row exists (operator-confirmed 2026-05-21)
INSERT INTO ref_recipes (name, classification, subtype, recipe_short_name, is_active, notes)
SELECT 'Qrew - Diversion Gose', 'Neb', 'CollabIn', 'Qrew - Diversion Gose', 1, 'Collab brew, brewed at Nébuleuse'
WHERE NOT EXISTS (SELECT 1 FROM ref_recipes WHERE name = 'Qrew - Diversion Gose' AND vintage = '');

INSERT IGNORE INTO ref_recipe_aliases (alias, recipe_id, notes)
SELECT 'QDG', id, 'beer.js + tank-simulator.php _SKU_BEER_MAP; operator-confirmed 2026-05-21; migration 065'
FROM ref_recipes WHERE name = 'Qrew - Diversion Gose' AND vintage = '' LIMIT 1;

-- ──────────────────────────────────────────────────────────────────────────────
-- 2. Alternate canonical-name aliases from BSF!BeerTypes col I + lib/beer.js _BEER_NAME_MAP
-- ──────────────────────────────────────────────────────────────────────────────

-- DrunkBeard - Galactic Drift → several forms seen in data
INSERT IGNORE INTO ref_recipe_aliases (alias, recipe_id, notes)
SELECT 'Galactic Drift', id, 'Shortened form seen in BSF!BeerTypes!I32; source: BSF!BeerTypes col I; migration 065'
FROM ref_recipes WHERE name = 'DrunkBeard - Galactic Drift' AND vintage = '' LIMIT 1;

INSERT IGNORE INTO ref_recipe_aliases (alias, recipe_id, notes)
SELECT 'Drunkbeard - Galactic Drift', id, 'Case-variant seen in BSF!BeerTypes!I32; source: BSF!BeerTypes col I; migration 065'
FROM ref_recipes WHERE name = 'DrunkBeard - Galactic Drift' AND vintage = '' LIMIT 1;

-- Docks - NEIPA aliases
INSERT IGNORE INTO ref_recipe_aliases (alias, recipe_id, notes)
SELECT 'Dockeuse', id, 'Alternate name used in lib/beer.js _BEER_NAME_MAP (Les Docks - NEIPA → Dockeuse); source: lib/beer.js + BSF!BeerTypes!I30; migration 065'
FROM ref_recipes WHERE name = 'Docks - NEIPA' AND vintage = '' LIMIT 1;

INSERT IGNORE INTO ref_recipe_aliases (alias, recipe_id, notes)
SELECT 'Les Docks - NEIPA', id, 'Alternate prefix form used in lib/beer.js _BEER_NAME_MAP; source: lib/beer.js; migration 065'
FROM ref_recipes WHERE name = 'Docks - NEIPA' AND vintage = '' LIMIT 1;

-- Diversion Blanche ↔ canonical split from lib/beer.js _BEER_NAME_MAP
INSERT IGNORE INTO ref_recipe_aliases (alias, recipe_id, notes)
SELECT 'Diversion Blanche', id, 'Full unabbreviated form (bd_racking.neb_beer uses this); source: bd_racking.neb_beer + lib/beer.js _BEER_NAME_MAP; migration 065'
FROM ref_recipes WHERE name = 'Diversion Blanche' AND vintage = '' LIMIT 1;

-- Diversion Gose
INSERT IGNORE INTO ref_recipe_aliases (alias, recipe_id, notes)
SELECT 'Diversion Gose', id, 'Full unabbreviated form (bd_racking.neb_beer uses this); source: bd_racking.neb_beer + lib/beer.js _BEER_NAME_MAP; migration 065'
FROM ref_recipes WHERE name = 'Diversion Gose' AND vintage = '' LIMIT 1;

-- Diversion Panaché
INSERT IGNORE INTO ref_recipe_aliases (alias, recipe_id, notes)
SELECT 'Diversion Panaché', id, 'Full unabbreviated form (bd_racking.neb_beer uses this); source: bd_racking.neb_beer; migration 065'
FROM ref_recipes WHERE name = 'Diversion Panaché' AND vintage = '' LIMIT 1;

-- MeltingPote - IPA alias already exists (id=4); adding Cropette→Cropette if not present
-- (row 4 already: alias='MeltingPote - IPA' → recipe_id=41=MeltingPote - Cropette)

-- EPH aliases: "Ephémère 1/2/3/4" (BSF!BeerTypes!I col)
-- EPH1 - various vintages; alias "Ephémère 1" and "Beer des Rosses" (2026 only)
INSERT IGNORE INTO ref_recipe_aliases (alias, recipe_id, notes)
SELECT 'Ephémère 1', id, 'Human-readable alias from BSF!BeerTypes!I59-63; applies across all EPH1 vintages — resolver must pick current vintage; migration 065'
FROM ref_recipes WHERE name = 'EPH1' AND vintage = '2026' LIMIT 1;

-- Note: "Beer des Rosses" is the 2026 edition name
INSERT IGNORE INTO ref_recipe_aliases (alias, recipe_id, notes)
SELECT 'Beer des Rosses', id, 'Trade name for EPH1 vintage 2026; source: BSF!BeerTypes!I63; migration 065'
FROM ref_recipes WHERE name = 'EPH1' AND vintage = '2026' LIMIT 1;

-- EPH2 aliases
INSERT IGNORE INTO ref_recipe_aliases (alias, recipe_id, notes)
SELECT 'Ephémère 2', id, 'Human-readable alias; applies across EPH2 vintages; resolver picks current; migration 065'
FROM ref_recipes WHERE name = 'EPH2' AND vintage = '2025' LIMIT 1;

INSERT IGNORE INTO ref_recipe_aliases (alias, recipe_id, notes)
SELECT 'Chela', id, 'Trade name used for EPH2 editions; source: BSF!BeerTypes!I64-68; migration 065'
FROM ref_recipes WHERE name = 'EPH2' AND vintage = '2025' LIMIT 1;

-- EPH3 aliases
INSERT IGNORE INTO ref_recipe_aliases (alias, recipe_id, notes)
SELECT 'Ephémère 3', id, 'Human-readable alias; applies across EPH3 vintages; migration 065'
FROM ref_recipes WHERE name = 'EPH3' AND vintage = '2024' LIMIT 1;

INSERT IGNORE INTO ref_recipe_aliases (alias, recipe_id, notes)
SELECT 'Baies-Tises', id, 'Trade name used for EPH3 editions; source: BSF!BeerTypes!I69-72; migration 065'
FROM ref_recipes WHERE name = 'EPH3' AND vintage = '2024' LIMIT 1;

-- EPH4 aliases (including Malt Capone — already has alias id=5 pointing to vintage 2023)
INSERT IGNORE INTO ref_recipe_aliases (alias, recipe_id, notes)
SELECT 'Ephémère 4', id, 'Human-readable alias; applies across EPH4 vintages; migration 065'
FROM ref_recipes WHERE name = 'EPH4' AND vintage = '2025' LIMIT 1;

-- "Malt Capone" already exists as alias id=5 → EPH4 vintage=2023; no duplicate

-- ──────────────────────────────────────────────────────────────────────────────
-- 3. Date-coded EPH aliases from bd_fermenting.beers_to_read
--    Pattern: EPH{slot}{month_2digit}{year_2digit}  e.g. EPH0221 = EPH2 slot 2021
--    Note: month digit is unreliable (EPH03 used without month) — year is the key.
--    We decode EPHnnYY where nn≤04 as slot+year.
-- ──────────────────────────────────────────────────────────────────────────────

-- EPH0221 → EPH2 vintage=2021
INSERT IGNORE INTO ref_recipe_aliases (alias, recipe_id, notes)
SELECT 'EPH0221', id, 'Date-coded form EPH{slot=02}{year=21} = EPH2 vintage 2021; observed in bd_fermenting.beers_to_read April 2021; migration 065'
FROM ref_recipes WHERE name = 'EPH2' AND vintage = '2021' LIMIT 1;

-- EPH0321 → EPH3 vintage=2021
INSERT IGNORE INTO ref_recipe_aliases (alias, recipe_id, notes)
SELECT 'EPH0321', id, 'Date-coded form EPH{slot=03}{year=21} = EPH3 vintage 2021; observed August 2021; migration 065'
FROM ref_recipes WHERE name = 'EPH3' AND vintage = '2021' LIMIT 1;

-- EPH0421 → EPH4 vintage=2021
INSERT IGNORE INTO ref_recipe_aliases (alias, recipe_id, notes)
SELECT 'EPH0421', id, 'Date-coded form EPH{slot=04}{year=21} = EPH4 vintage 2021; observed Sep-Oct 2021; migration 065'
FROM ref_recipes WHERE name = 'EPH4' AND vintage = '2021' LIMIT 1;

-- EPH01 → EPH1 vintage=2022 (short form without month digit, March 2022)
INSERT IGNORE INTO ref_recipe_aliases (alias, recipe_id, notes)
SELECT 'EPH01', id, 'Short date-coded form EPH{slot=1} without month, March 2022 → EPH1 vintage 2022; migration 065'
FROM ref_recipes WHERE name = 'EPH1' AND vintage = '2022' LIMIT 1;

-- EPH0222 → EPH2 vintage=2022
INSERT IGNORE INTO ref_recipe_aliases (alias, recipe_id, notes)
SELECT 'EPH0222', id, 'Date-coded form EPH{slot=02}{year=22} = EPH2 vintage 2022; observed April-May 2022; migration 065'
FROM ref_recipes WHERE name = 'EPH2' AND vintage = '2022' LIMIT 1;

-- EPH03 → EPH3 vintage=2022 (short form, August 2022)
INSERT IGNORE INTO ref_recipe_aliases (alias, recipe_id, notes)
SELECT 'EPH03', id, 'Short date-coded form EPH{slot=3} without year/month, August 2022 → EPH3 vintage 2022; migration 065'
FROM ref_recipes WHERE name = 'EPH3' AND vintage = '2022' LIMIT 1;

-- EPH0422 → EPH4 vintage=2022
INSERT IGNORE INTO ref_recipe_aliases (alias, recipe_id, notes)
SELECT 'EPH0422', id, 'Date-coded form EPH{slot=04}{year=22} = EPH4 vintage 2022; observed Sep-Oct 2022; migration 065'
FROM ref_recipes WHERE name = 'EPH4' AND vintage = '2022' LIMIT 1;

-- EPH0123 → EPH1 vintage=2023
INSERT IGNORE INTO ref_recipe_aliases (alias, recipe_id, notes)
SELECT 'EPH0123', id, 'Date-coded form EPH{slot=01}{year=23} = EPH1 vintage 2023; observed February 2023; migration 065'
FROM ref_recipes WHERE name = 'EPH1' AND vintage = '2023' LIMIT 1;

-- EPH0223 → EPH2 vintage=2023
INSERT IGNORE INTO ref_recipe_aliases (alias, recipe_id, notes)
SELECT 'EPH0223', id, 'Date-coded form EPH{slot=02}{year=23} = EPH2 vintage 2023; observed April-May 2023; migration 065'
FROM ref_recipes WHERE name = 'EPH2' AND vintage = '2023' LIMIT 1;

-- EPH323 → EPH3 vintage=2023 (missing leading zero, August 2023)
INSERT IGNORE INTO ref_recipe_aliases (alias, recipe_id, notes)
SELECT 'EPH323', id, 'Malformed date-coded form (missing leading zero), August 2023 → EPH3 vintage 2023; source: bd_fermenting.beers_to_read; migration 065'
FROM ref_recipes WHERE name = 'EPH3' AND vintage = '2023' LIMIT 1;

-- ──────────────────────────────────────────────────────────────────────────────
-- 4. bd_fermenting.beers_to_cold_crash abbreviated forms
--    These are operator shorthand used in the cold-crash column only.
-- ──────────────────────────────────────────────────────────────────────────────

-- BF-915 → BadFish - 915
INSERT IGNORE INTO ref_recipe_aliases (alias, recipe_id, notes)
SELECT 'BF-915', id, 'Cold-crash abbreviation; source: bd_fermenting.beers_to_cold_crash; migration 065'
FROM ref_recipes WHERE name = 'BadFish - 915' AND vintage = '' LIMIT 1;

-- BF-Cryo → BadFish - Cryo IPA
INSERT IGNORE INTO ref_recipe_aliases (alias, recipe_id, notes)
SELECT 'BF-Cryo', id, 'Cold-crash abbreviation; source: bd_fermenting.beers_to_cold_crash; migration 065'
FROM ref_recipes WHERE name = 'BadFish - Cryo IPA' AND vintage = '' LIMIT 1;

-- BF-Wittshark / BF-Wit. → BadFish - Witshark
INSERT IGNORE INTO ref_recipe_aliases (alias, recipe_id, notes)
SELECT 'BF-Wittshark', id, 'Cold-crash abbreviation (double-t typo); source: bd_fermenting.beers_to_cold_crash; migration 065'
FROM ref_recipes WHERE name = 'BadFish - Witshark' AND vintage = '' LIMIT 1;

INSERT IGNORE INTO ref_recipe_aliases (alias, recipe_id, notes)
SELECT 'BF-Wit.', id, 'Cold-crash abbreviation (truncated); source: bd_fermenting.beers_to_cold_crash; migration 065'
FROM ref_recipes WHERE name = 'BadFish - Witshark' AND vintage = '' LIMIT 1;

-- BLZ-IPA → BLZ Company - Mosaic IPA (the IPA from BLZ batch context)
-- Note: "BLZ-IPA" maps to Mosaic IPA based on context; BZ-IPA also seen
INSERT IGNORE INTO ref_recipe_aliases (alias, recipe_id, notes)
SELECT 'BLZ-IPA', id, 'Cold-crash abbreviation for BLZ Company IPA; source: bd_fermenting.beers_to_cold_crash; migration 065'
FROM ref_recipes WHERE name = 'BLZ Company - Mosaic IPA' AND vintage = '' LIMIT 1;

INSERT IGNORE INTO ref_recipe_aliases (alias, recipe_id, notes)
SELECT 'BZ-IPA', id, 'Cold-crash abbreviation variant; source: bd_fermenting.beers_to_cold_crash; migration 065'
FROM ref_recipes WHERE name = 'BLZ Company - Mosaic IPA' AND vintage = '' LIMIT 1;

-- BLZ-Lager → BLZ Company - Lager
INSERT IGNORE INTO ref_recipe_aliases (alias, recipe_id, notes)
SELECT 'BLZ-Lager', id, 'Cold-crash abbreviation; source: bd_fermenting.beers_to_cold_crash; migration 065'
FROM ref_recipes WHERE name = 'BLZ Company - Lager' AND vintage = '' LIMIT 1;

INSERT IGNORE INTO ref_recipe_aliases (alias, recipe_id, notes)
SELECT 'BLZ-Mosaic', id, 'Cold-crash abbreviation; source: bd_fermenting.beers_to_cold_crash; migration 065'
FROM ref_recipes WHERE name = 'BLZ Company - Mosaic IPA' AND vintage = '' LIMIT 1;

-- BLZ-PA → BLZ Company - WestCoast Pale Ale
INSERT IGNORE INTO ref_recipe_aliases (alias, recipe_id, notes)
SELECT 'BLZ-PA', id, 'Cold-crash abbreviation; source: bd_fermenting.beers_to_cold_crash; migration 065'
FROM ref_recipes WHERE name = 'BLZ Company - WestCoast Pale Ale' AND vintage = '' LIMIT 1;

INSERT IGNORE INTO ref_recipe_aliases (alias, recipe_id, notes)
SELECT 'BZ-PA', id, 'Cold-crash abbreviation variant; source: bd_fermenting.beers_to_cold_crash; migration 065'
FROM ref_recipes WHERE name = 'BLZ Company - WestCoast Pale Ale' AND vintage = '' LIMIT 1;

-- BZ-RA → BLZ Company - Red Ale
INSERT IGNORE INTO ref_recipe_aliases (alias, recipe_id, notes)
SELECT 'BZ-RA', id, 'Cold-crash abbreviation; source: bd_fermenting.beers_to_cold_crash; migration 065'
FROM ref_recipes WHERE name = 'BLZ Company - Red Ale' AND vintage = '' LIMIT 1;

-- CB-Bamse → Chien Bleu - Bamse
INSERT IGNORE INTO ref_recipe_aliases (alias, recipe_id, notes)
SELECT 'CB-Bamse', id, 'Cold-crash abbreviation; source: bd_fermenting.beers_to_cold_crash; migration 065'
FROM ref_recipes WHERE name = 'Chien Bleu - Bamse' AND vintage = '' LIMIT 1;

-- CB-Jasper → Chien Bleu - Jasper
INSERT IGNORE INTO ref_recipe_aliases (alias, recipe_id, notes)
SELECT 'CB-Jasper', id, 'Cold-crash abbreviation; source: bd_fermenting.beers_to_cold_crash; migration 065'
FROM ref_recipes WHERE name = 'Chien Bleu - Jasper' AND vintage = '' LIMIT 1;

-- CB-Pomelo → Chien Bleu - Pomelo
INSERT IGNORE INTO ref_recipe_aliases (alias, recipe_id, notes)
SELECT 'CB-Pomelo', id, 'Cold-crash abbreviation; source: bd_fermenting.beers_to_cold_crash; migration 065'
FROM ref_recipes WHERE name = 'Chien Bleu - Pomelo' AND vintage = '' LIMIT 1;

-- CH-4.4 / CH-4,4 → Brasserie du Château - 4.4
INSERT IGNORE INTO ref_recipe_aliases (alias, recipe_id, notes)
SELECT 'CH-4.4', id, 'Cold-crash abbreviation; source: bd_fermenting.beers_to_cold_crash; migration 065'
FROM ref_recipes WHERE name = 'Brasserie du Château - 4.4' AND vintage = '' LIMIT 1;

INSERT IGNORE INTO ref_recipe_aliases (alias, recipe_id, notes)
SELECT 'CH-4,4', id, 'Cold-crash abbreviation (comma decimal); source: bd_fermenting.beers_to_cold_crash; migration 065'
FROM ref_recipes WHERE name = 'Brasserie du Château - 4.4' AND vintage = '' LIMIT 1;

-- CH-Faya → Brasserie du Château - Faya
INSERT IGNORE INTO ref_recipe_aliases (alias, recipe_id, notes)
SELECT 'CH-Faya', id, 'Cold-crash abbreviation; source: bd_fermenting.beers_to_cold_crash; migration 065'
FROM ref_recipes WHERE name = 'Brasserie du Château - Faya' AND vintage = '' LIMIT 1;

-- CH-Ginger / CH-GI → Brasserie du Château - Ginger
INSERT IGNORE INTO ref_recipe_aliases (alias, recipe_id, notes)
SELECT 'CH-Ginger', id, 'Cold-crash abbreviation; source: bd_fermenting.beers_to_cold_crash; migration 065'
FROM ref_recipes WHERE name = 'Brasserie du Château - Ginger' AND vintage = '' LIMIT 1;

INSERT IGNORE INTO ref_recipe_aliases (alias, recipe_id, notes)
SELECT 'CH-GI', id, 'Cold-crash abbreviation (ultra-short); source: bd_fermenting.beers_to_cold_crash; migration 065'
FROM ref_recipes WHERE name = 'Brasserie du Château - Ginger' AND vintage = '' LIMIT 1;

-- MN-PuraVida → Moutonoir - Pura Vida
INSERT IGNORE INTO ref_recipe_aliases (alias, recipe_id, notes)
SELECT 'MN-PuraVida', id, 'Cold-crash abbreviation; source: bd_fermenting.beers_to_cold_crash; migration 065'
FROM ref_recipes WHERE name = 'Moutonoir - Pura Vida' AND vintage = '' LIMIT 1;

-- FE-Hoppy → Brasserie du Fennek - Hoppy Wheat
INSERT IGNORE INTO ref_recipe_aliases (alias, recipe_id, notes)
SELECT 'FE-Hoppy', id, 'Cold-crash abbreviation; source: bd_fermenting.beers_to_cold_crash; migration 065'
FROM ref_recipes WHERE name = 'Brasserie du Fennek - Hoppy Wheat' AND vintage = '' LIMIT 1;

-- LIM-Pale / LIM-Kinzan / LIM-Blanche → L'Improbable series
INSERT IGNORE INTO ref_recipe_aliases (alias, recipe_id, notes)
SELECT 'LIM-Pale', id, 'Cold-crash abbreviation; source: bd_fermenting.beers_to_cold_crash; migration 065'
FROM ref_recipes WHERE name = "L'Improbable - Pale Ale" AND vintage = '' LIMIT 1;

INSERT IGNORE INTO ref_recipe_aliases (alias, recipe_id, notes)
SELECT 'LIM-Kinzan', id, 'Cold-crash abbreviation; source: bd_fermenting.beers_to_cold_crash; migration 065'
FROM ref_recipes WHERE name = "L'Improbable - Kinzan" AND vintage = '' LIMIT 1;

INSERT IGNORE INTO ref_recipe_aliases (alias, recipe_id, notes)
SELECT 'LIM-Blanche', id, 'Cold-crash abbreviation; source: bd_fermenting.beers_to_cold_crash; migration 065'
FROM ref_recipes WHERE name = "L'Improbable - White Trash" AND vintage = '' LIMIT 1;

-- BLZ-Mosaic 2 is just "BLZ-Mosaic" + batch — handled by resolver batch-strip

-- ──────────────────────────────────────────────────────────────────────────────
-- 5. Operator-confirmed aliases 2026-05-21
--    Les Combières (id=39 La Grande à Meylan, id=40 La P'tite à Piguet) + QDG done above
-- ──────────────────────────────────────────────────────────────────────────────

-- CO-blanche → Les Combières - La Grande à Meylan (id=39)
INSERT IGNORE INTO ref_recipe_aliases (alias, recipe_id, notes)
SELECT 'CO-blanche', id, 'bd_fermenting.beers_to_cold_crash + operator-confirmed 2026-05-21: Blanche = La Grande à Meylan; migration 065'
FROM ref_recipes WHERE id = 39 LIMIT 1;

-- CO-GAM → Les Combières - La Grande à Meylan (id=39)
INSERT IGNORE INTO ref_recipe_aliases (alias, recipe_id, notes)
SELECT 'CO-GAM', id, 'bd_fermenting.beers_to_cold_crash + operator-confirmed 2026-05-21: GAM = Grande à Meylan; migration 065'
FROM ref_recipes WHERE id = 39 LIMIT 1;

-- Les Combières - Blanche → Les Combières - La Grande à Meylan (id=39)
INSERT IGNORE INTO ref_recipe_aliases (alias, recipe_id, notes)
SELECT 'Les Combières - Blanche', id, 'operator-confirmed alias for La Grande à Meylan; migration 065'
FROM ref_recipes WHERE id = 39 LIMIT 1;

-- COM-BLO → Les Combières - La P'tite à Piguet (id=40)
INSERT IGNORE INTO ref_recipe_aliases (alias, recipe_id, notes)
SELECT 'COM-BLO', id, 'bd_fermenting.beers_to_cold_crash + operator-confirmed 2026-05-21: Blonde = La P\'tite à Piguet; migration 065'
FROM ref_recipes WHERE id = 40 LIMIT 1;

-- Les Combières - Blonde → Les Combières - La P'tite à Piguet (id=40)
INSERT IGNORE INTO ref_recipe_aliases (alias, recipe_id, notes)
SELECT 'Les Combières - Blonde', id, 'operator-confirmed alias; migration 065'
FROM ref_recipes WHERE id = 40 LIMIT 1;

COMMIT;
