# Recipe reclassification backlog — chantier 030

Snapshot DB : 2026-05-08 post-cleanup yeast.

## Règle de tri (rappel)

Branding intègre l'identité Nébuleuse → **COLLAB**
Branding 100% client externe → **CONTRACT**

Lieu de production indépendant. Voir `CLAUDE.md` section DETTE TECHNIQUE.

## 42 recettes actuellement classifiées Contract

À auditer case par case lors du chantier 030.

| ✅/❌ | Client (id) | Recipe (id) | Decision (CONTRACT / COLLAB) | Notes |
|---|---|---|---|---|
| ☐ | Abbaye de St-Maurice (1) | Candide (1) | | |
| ☐ | Abbaye de St-Maurice (1) | DXV (2) | | |
| ☐ | Abbaye de St-Maurice (1) | Febris (3) | | |
| ☐ | Abbaye de St-Maurice (1) | Lumen (4) | | |
| ☐ | Abbaye de St-Maurice (1) | Vox (5) | | |
| ☐ | BadFish (3) | 915 (7) | | |
| ☐ | BadFish (3) | Cryo IPA (8) | | |
| ☐ | BadFish (3) | Witshark (9) | | |
| ☐ | BLZ Company (2) | Lager (11) | | |
| ☐ | BLZ Company (2) | Mosaic IPA (12) | | |
| ☐ | BLZ Company (2) | Red Ale (13) | | |
| ☐ | BLZ Company (2) | WestCoast Pale Ale (14) | | |
| ☐ | Brasserie du Château (4) | 4.4 (15) | | |
| ☐ | Brasserie du Château (4) | Faya (16) | | |
| ☐ | Brasserie du Château (4) | Ginger (17) | | |
| ☐ | Brasserie du Fennek (5) | Hoppy Wheat (18) | | |
| ☐ | Brasserie du Singe (6) | Wheat IPA (19) | | |
| ☐ | Brasserie28 / TM (7) | TM-BLO (53) | | |
| ☐ | Brasserie28 / TM (7) | TM-IPA (54) | | |
| ☐ | Brasserie28 / TM (7) | TM-ST (55) | | |
| ☐ | Brasserie28 / TM (7) | TM-TR (56) | | |
| ☐ | Chien Bleu (8) | Bamse (20) | | |
| ☐ | Chien Bleu (8) | Jasper (21) | | |
| ☐ | Chien Bleu (8) | Moût Chaud (22) | | |
| ☐ | Chien Bleu (8) | Moût Froid (23) | | |
| ☐ | Chien Bleu (8) | Pomelo (24) | | |
| ☐ | L'Improbable (9) | Kinzan (34) | | |
| ☐ | L'Improbable (9) | Pale Ale (35) | | |
| ☐ | L'Improbable (9) | White Trash (36) | | |
| ☐ | Le Traquenard (10) | Pale Ale (37) | | |
| ☐ | Le Traquenard (10) | Session IPA (38) | | |
| ☐ | Les Combières (11) | La Grande à Meylan (39) | | |
| ☐ | Les Combières (11) | La P'tite à Piguet (40) | | |
| ☐ | MeltingPote (12) | Cropette (41) | CONTRACT | exemple canonique du briefing 030 |
| ☐ | MeltingPote (12) | Jonx (42) | | |
| ☐ | MeltingPote (12) | Plainpal (43) | | |
| ☐ | Moutonoir (13) | Pura Vida (45) | | |
| ☐ | Nylo (14) | NYL (46) | | |
| ☐ | Obrist (15) | Grape Ale (47) | | |
| ☐ | Septentrion (16) | Boréale (48) | | |
| ☐ | Septentrion (16) | Minami (49) | | |
| ☐ | Septentrion (16) | Mistral (50) | | |

## Cas déjà bien classifiés (pour info)

Subtype `CollabIn` actuellement (pas à requalifier, juste à cartographier en `collab` simple lors du 030) :

- id=27 Diversion Gose
- id=29 Docks - NEIPA
- id=31 DrunkBeard - Galactic Drift

> Note : le briefing 030 indiquait que Docks - NEIPA serait « mal classifiée en BSF » — la DB est en fait correcte (CollabIn). Donc divergence BSF/DB à régler côté source quand on ré-aligne.

## Workflow 030

1. Audit ligne par ligne ci-dessus → cocher ☑ + écrire decision dans col 4
2. Migration corrective une fois validée (passer les ✅COLLAB en `subtype='CollabIn'` + `classification='Neb'`, ou attendre la nouvelle modélisation `ref_recipe_families`)
3. Si refonte 030 démarre avant validation complète : ré-incorporer ce backlog dans la nouvelle table `ref_recipe_families.category`
