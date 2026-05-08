# CLAUDE.md — maltyweb

Web frontend for La Nébuleuse brewery, paired with maltytask (Google Sheets pipeline) and the maltyweb MySQL DB on the VPS at 83.228.215.243.

## DETTE TECHNIQUE — Modèle recettes

Le modèle actuel `ref_recipes (name, vintage)` traite toutes les recettes
de manière uniforme alors que la réalité métier en distingue plusieurs types
avec des comportements vintage différents.

### Limites du modèle actuel

1. **ref_recipe_aliases est mono-cible** : un alias pointe vers UNE recipe précise
   (vintage incluse). Pour aliases qui devraient couvrir plusieurs vintages
   (ex: re-utilisation d'un nom commercial sur un Core range), créer des aliases
   distincts avec préfixe année.

2. **Vintage sémantique non distinguée** : Core range et EPH sont stockés
   identiquement alors que :
   - Core range : recette globalement stable, vintage optionnel (production continue)
   - EPH : chaque vintage est une recette conceptuellement DIFFÉRENTE
     (style, ingrédients, branding différents)

3. **Pas de catégorisation propre** : la classification Contract/Collab/Core/Archive
   est imparfaite et certaines lignes mal classifiées historiquement.

4. **White labels non trackés** : 1-4 palettes par run de bottling, mêmes Core ranges
   avec étiquette client spécifique. Aucune trace en DB aujourd'hui.

### Règle métier — Collab vs Contract

La distinction se fait sur le BRANDING, pas le lieu de production :
- Branding intègre l'identité La Nébuleuse → COLLAB
- Branding 100% client externe → CONTRACT

Lieu de production (chez nous / chez l'autre brasseur) = dimension indépendante
de cette classification.

Exemples canoniques :
- DrunkBeard - Galactic Drift 2.0 → COLLAB (co-branding)
- Les Docks - NEIPA → COLLAB (branding intègre Nébuleuse)
- MeltingPote - Cropette → CONTRACT (branding 100% MeltingPote)

### Chantier futur 030 — Refonte modèle recipes

Refactor cible :
- `ref_recipe_families (id, name UNIQUE, category ENUM, status ENUM, owner ENUM, owner_name)`
  - category : 'core_active' | 'core_archived' | 'ephemeral' | 'collab' | 'contract' | 'white_label'
  - status : 'active' | 'archived' | 'one_shot'
  - owner : 'nebuleuse' | 'external'
- `ref_recipe_versions (id, family_id FK, vintage, og_target, abv_target, ibu_target, is_active)`
  - UNIQUE (family_id, vintage)
  - Pour Core : généralement 1 version (vintage = '')
  - Pour EPH : 1 version par millésime
- `ref_recipe_skus (id, family_id FK, label_type ENUM, client_id FK NULL, sku_code, format)`
  - label_type : 'standard_nebuleuse' | 'white_label'
- `ref_recipe_aliases (alias UNIQUE, family_id FK, vintage NULL)`
  - vintage NULL → alias remonte à la famille (Core range)
  - vintage NOT NULL → alias remonte à une instance précise (EPH)

À traiter quand l'inputting MaltyTask sera refait (forms remplaceront BSF).
Inclut migration des 76 ref_recipes actuels + audit reclassification
(notamment 42 lignes Contract dont certaines devraient passer en Collab).
Le backlog des 42 contracts vit dans `docs/recipe-reclassification-backlog.md`.

## DETTE TECHNIQUE — Cohérence colonne is_active sur les référentiels

Audit 2026-05-08 : la colonne `is_active TINYINT(1)` est inégalement présente
sur les tables `ref_*`. Pattern de soft-delete attendu pour les chantiers UI
admin MaltyTask (CRUD avec désactivation au lieu de suppression dure).

### Tables qui l'ont (5)
- `ref_recipes` (NOT NULL, pas de DEFAULT explicite ; 76 rows toutes active=1)
- `ref_mi` (NOT NULL ; 0 rows aujourd'hui)
- `ref_skus` (NOT NULL ; 0 rows aujourd'hui)
- `ref_suppliers` (NOT NULL ; 0 rows aujourd'hui)
- `ref_supplier_summary` (NOT NULL ; 0 rows aujourd'hui)

### Référentiels métier qui devraient l'avoir mais ne l'ont pas (6)

Critère : tables CRUD-able via la page paramètres MaltyTask, où la désactivation
est une opération métier valide (équipement retiré du service, client inactif,
catégorie obsolète, etc.).

- `ref_cct` (18 rows)
- `ref_bbt` (8 rows)
- `ref_yt` (3 rows)
- `ref_clients` (16 rows)
- `ref_mi_categories` (0 rows aujourd'hui, mais à venir)
- `ref_mi_subcategories` (0 rows aujourd'hui, mais à venir)

`ref_yeast_strains` était dans cette liste — colonne ajoutée par migration 026a.

### Tables aliases sans `is_active` (4 après 026b)

Moins critique mais à uniformiser : un alias deprecated peut rester pour
lookup historique sans suggestion en dropdown UI.

- `ref_yeast_strain_aliases`
- `ref_mi_aliases`
- `ref_supplier_aliases`
- `ref_recipe_aliases` (créée par 026b)

### Hors-scope

- `ref_sku_bom` : table computed (TRUNCATE+INSERT à chaque ingest), soft-delete
  non applicable (la donnée disparaît à la prochaine recompute si absente en
  source).

### MySQL 8.0 — limitations idempotence DDL

Liste des syntaxes IF EXISTS / IF NOT EXISTS supportées et NON supportées :

NON supporté (échec sur MySQL 8.0.45 — empiriquement testé) :
- `ALTER TABLE ADD COLUMN IF NOT EXISTS` (extension MariaDB)
- `ALTER TABLE ADD CONSTRAINT IF NOT EXISTS`
- `ALTER TABLE DROP FOREIGN KEY IF EXISTS`
- `ALTER TABLE DROP CONSTRAINT IF EXISTS` (NI pour FK, NI pour CHECK — la syntaxe `IF EXISTS` est rejetée)
- `ALTER TABLE DROP CHECK IF EXISTS`
- `ALTER TABLE DROP INDEX IF EXISTS`

Supporté :
- `CREATE TABLE IF NOT EXISTS`
- `CREATE INDEX IF NOT EXISTS`
- `DROP INDEX IF EXISTS` *(en standalone DROP INDEX, pas dans ALTER TABLE)*
- `DROP TABLE IF EXISTS`
- `INSERT IGNORE` / `ON DUPLICATE KEY UPDATE`
- `INSERT ... ON DUPLICATE KEY UPDATE`

Conséquence pratique : tout `ALTER TABLE` est non-idempotent. En cas d'échec mid-migration, le mode opératoire est restore depuis backup + fix + re-apply propre, pas « relance en l'état ».

### Restrictions FK / CHECK constraints

Une colonne incluse dans une FK avec `ON UPDATE CASCADE`, `ON UPDATE SET NULL`, ou `ON DELETE SET NULL` ne peut PAS être utilisée dans une `CHECK CONSTRAINT`. MySQL retourne erreur 3823 (`Column X cannot be used in a check constraint: needed in a foreign key constraint referential action`).

Workaround : utiliser `ON UPDATE RESTRICT` (default) ou `NO ACTION` sur la FK, ou retirer la clause `ON UPDATE`. Pour les FK référençant un `INT AUTO_INCREMENT`, `ON UPDATE CASCADE` est de toute façon un no-op (les ids ne changent pas) — sa suppression est sans risque.

Validé empiriquement le 2026-05-08 sur MySQL 8.0.45 lors de la migration 026 (6 tentatives de syntaxes invalides corrigées au fur et à mesure).

### Recommandation

Migration consolidée `028_uniformize_is_active.sql` à appliquer **avant**
les chantiers UI admin MaltyTask des référentiels concernés. Pattern :

```sql
ALTER TABLE ref_cct           ADD COLUMN is_active TINYINT(1) NOT NULL DEFAULT 1;
ALTER TABLE ref_bbt           ADD COLUMN is_active TINYINT(1) NOT NULL DEFAULT 1;
ALTER TABLE ref_yt            ADD COLUMN is_active TINYINT(1) NOT NULL DEFAULT 1;
ALTER TABLE ref_clients       ADD COLUMN is_active TINYINT(1) NOT NULL DEFAULT 1;
ALTER TABLE ref_mi_categories ADD COLUMN is_active TINYINT(1) NOT NULL DEFAULT 1;
ALTER TABLE ref_mi_subcategories ADD COLUMN is_active TINYINT(1) NOT NULL DEFAULT 1;
-- + les 4 tables aliases si on uniformise jusqu'au bout
```

Note collatérale : `ref_recipes.is_active` n'a pas de DEFAULT explicite — à
ajouter `DEFAULT 1` dans la même migration pour aligner avec `ref_mi` /
`ref_suppliers` / `ref_skus`.
