# Salle des Machines — Fournisseurs : Plan Write Endpoints (Step 3)

_Rédigé 2026-05-25. Révisé 2026-05-25 après revue DBA + revue d'implémentation
(2 agents). Les 5 questions en suspens sont désormais **résolues** (voir
§ Décisions résolues). Aucune écriture dans `ref_*` n'est effectuée en step 2 ;
les migrations 125/126 sont écrites mais **non appliquées** (en attente
d'approbation opérateur)._

---

## Modèle de gouvernance (rappel)

| Trust state | Origine | Résultat ingest suivant |
|---|---|---|
| `auto` | ingest pipeline — non confirmé | peut être écrasé |
| `vérifié` | admin a confirmé la valeur | sera respecté mais peut être écrasé si nouvelle évidence |
| `verrouillé (pinned)` | admin a épinglé via `ref_supplier_field_pins` | jamais écrasé par ingest |
| `gap` | champ admin-only jamais renseigné | ingest ne peut pas le remplir |

---

## ⚠️ Conventions house-style (corrigent la v1 de ce plan)

La v1 référençait `logAudit()` — **c'est le mécanisme TS/JSONL** (`data/audit-log.jsonl`),
pas celui des endpoints PHP. Les endpoints write PHP doivent utiliser :

- **Audit :** `log_revision($pdo, $me, $table, $pk, $before, $after, $qcFlag, $note?)`
  → table SQL `audit_row_revisions` (une ligne par modification). PAS `logAudit()`.
- **CSRF :** `csrf_verify($_POST['csrf'] ?? null)` (token session `$_SESSION['csrf']`,
  comparaison `hash_equals`). PAS de double-submit header custom.
- **Transaction :** `$pdo->beginTransaction()` / `commit()` / `rollBack()` autour de
  chaque write multi-étapes ; POST-only ; rate-limit via `rl_check_and_log`.
- **Snapshot :** avant tout UPDATE destructif → `data/snapshots/ref_suppliers-{id}-{ts}.json`.
- **Two-step COGS-impacting :** un simple second POST avec le **même** token CSRF de
  session (PAS un token one-time stocké côté serveur — browser-hostile et inutile).
- **Param validation :** pattern en deux temps — lire avec défaut `??` PUIS valider
  (anti-pattern PHP query-param NULL fall-through).

---

## Endpoints à créer

### 1. `POST /api/sf-pin-field.php` — Épingler / désépingler un champ

**Tables cibles :** `ref_supplier_field_pins`

**Paramètres POST :**
```
supplier_fk   INT UNSIGNED  (id de ref_suppliers)
field_name    VARCHAR(64)   (gl_account | currency | country | vat_regime | vat_number | parser_key | hors_perimetre_cogs | sporadique)
pinned_value  TEXT          (valeur à épingler; NULL pour désépingler)
pin_reason    TEXT          (optionnel; affiché dans la fiche)
action        ENUM          (pin | unpin)
```

**Logique :**
1. `csrf_verify()` + `is_admin()`.
2. Valider `field_name` contre une whitelist explicite (jamais d'interpolation d'identifiant).
3. `action=pin` → UPSERT dans `ref_supplier_field_pins` :
   ```sql
   INSERT INTO ref_supplier_field_pins (supplier_fk, field_name, pinned_value, pinned_by, pin_reason)
   VALUES (?, ?, ?, ?, ?)
   ON DUPLICATE KEY UPDATE
     pinned_value = VALUES(pinned_value),
     pinned_by    = VALUES(pinned_by),
     pinned_at    = NOW(),
     pin_reason   = VALUES(pin_reason);
   ```
4. `action=unpin` → `DELETE FROM ref_supplier_field_pins WHERE supplier_fk=? AND field_name=?`
5. Mettre à jour `ref_suppliers.last_modified_by='web'` + `last_seen_at=NOW()`.
6. `log_revision($pdo, $me, 'ref_supplier_field_pins', $supplier_fk, $before, $after, 'normal', 'field-pin'|'field-unpin')`.
7. Répondre `{ ok: true, pin: {...} }` ou `{ ok: false, error: "..." }`.

**Admin-gating :** `is_admin()` requis ; manager ne peut pas épingler.

**Idempotence :** UPSERT sur UNIQUE(supplier_fk, field_name) → re-run est no-op.

---

### 2. `POST /api/sf-update-field.php` — Modifier un champ vérifié

**Tables cibles :** `ref_suppliers` (admin) ou `ref_supplier_proposals` (manager).

**Paramètres POST :**
```
supplier_fk   INT UNSIGNED
field_name    VARCHAR(64)
new_value     TEXT
```

**Whitelist de champs modifiables directement :**
```
country, vat_number, vat_regime, parser_key, hors_perimetre_cogs,
sporadique, notes
```

_(gl_account et currency sont COGS-impacting → nécessitent confirmation
en deux temps avant de passer dans ce endpoint — voir house-style.)_

**Logique :**
1. `csrf_verify()` + session vérifiée (admin OU manager).
2. Lire `field_name` puis valider contre la whitelist (pas d'interpolation).
3. **Manager** → INSERT dans `ref_supplier_proposals` (proposition non appliquée,
   `status='pending'`) → `{ ok: true, pending: true }`. (Table dédiée — voir
   migration 125 ; on n'utilise PAS `mi_proposals_audit`.)
4. **Admin** → UPDATE direct :
   ```sql
   UPDATE ref_suppliers SET <field> = ?, last_modified_by = 'web' WHERE id = ?;
   ```
   Cas `country` : valider `^[A-Z]{2}$` (ISO-3166-1 alpha-2, majuscules) ;
   chaîne vide → `NULL` (efface le champ). Pas de piège PHP-strict (colonne
   toujours CHAR, jamais INT).
5. Si `field_name ∈ {gl_account, currency}` (COGS-impacting) :
   - Snapshot `data/snapshots/ref_suppliers-{id}-{ts}.json` AVANT.
   - Diff avant/après affiché dans la modal UI.
   - Deuxième POST de confirmation avec le même token CSRF de session.
6. `log_revision()` obligatoire par champ modifié.

**Idempotence :** UPDATE idempotent si même valeur → no-op propre.

---

### 3. `POST /api/sf-validate-supplier.php` — Valider une fiche (draft → active)

**Tables cibles :** `ref_suppliers`, `ref_supplier_field_pins`

> ⚠️ **Dormant aujourd'hui :** les 134 fournisseurs sont `commissioning_state='active'`.
> Le chemin draft→active ne se déclenche que pour un NOUVEAU fournisseur arrivé via
> ingest en `draft` (règle : `draft` ⟺ 0 livraison Active/Consumed **et** créé par
> ingest, identité non confirmée). L'endpoint reste une assurance bon marché.

**Paramètres POST :**
```
supplier_fk        INT UNSIGNED
confirmed_fields   JSON array  (liste de field_name à épingler simultanément)
```

**Logique :**
1. `csrf_verify()` + `is_admin()`.
2. `SELECT ... FOR UPDATE` sur la ligne → garde d'idempotence : si
   `commissioning_state != 'draft'` → `rollBack()` + `{ ok: true, already_active: true }`.
   (Le verrou PK empêche deux validations concurrentes de flipper deux fois.)
3. Snapshot la ligne complète dans `data/snapshots/`.
4. `UPDATE ... SET commissioning_state='active', last_modified_by='web'`.
5. Pour chaque field dans `confirmed_fields` (filtré sur whitelist) : UPSERT dans
   `ref_supplier_field_pins` avec la valeur courante + `log_revision()` par pin.
6. `log_revision()` du flip d'état (`{commissioning_state:'draft'}` → `{...:'active'}`).
7. `commit()` ; répondre `{ ok: true, supplier: {id, name, commissioning_state:'active'} }`.

**Test d'un draft synthétique (auto-nettoyant, même session) :**
```sql
INSERT INTO ref_suppliers (supplier_id, name, row_hash, commissioning_state)
VALUES ('TEST_DRAFT_9999', 'TEST DRAFT SUPPLIER', SHA2('TEST_DRAFT_9999',256), 'draft');
-- ... exercer l'endpoint ...
DELETE FROM ref_suppliers WHERE supplier_id = 'TEST_DRAFT_9999';   -- nettoyage obligatoire
```

---

### 4. `POST /api/sf-add-alias.php` — Ajouter un alias OCR

**Table cible :** `ref_supplier_aliases`

**Paramètres POST :**
```
supplier_fk   INT UNSIGNED
alias         VARCHAR(255)
source        ENUM('manual','observed')  default 'manual'
```

**Logique :**
1. `csrf_verify()` + `is_admin()`.
2. `INSERT IGNORE` (UNIQUE `uniq_alias` sur `alias` **seul**) → si collision,
   l'alias est **déjà pris** (par ce fournisseur ou un autre — la contrainte ne
   distingue pas). Message : `"Cet alias est déjà attribué."` Optionnel : faire un
   SELECT préalable pour préciser à quel fournisseur, et proposer un re-pointage.
3. `log_revision()`.

---

## Snapshot discipline

Avant tout UPDATE sur `ref_suppliers` ou DELETE sur `ref_supplier_field_pins` :
```php
$snap = json_encode($currentRow, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
file_put_contents("/var/www/maltytask/data/snapshots/ref_suppliers-{$id}-{$ts}.json", $snap);
```
Retenir les 5 derniers snapshots par supplier (purge auto à l'application).

---

## Décisions résolues (2026-05-25, revue DBA + implémentation)

### Q1 — `ref_supplier_gls` vide → **SEED depuis l'observé** ✅
Migration `126_seed_ref_supplier_gls_from_observed.sql` écrite (dry-run). La table
est conçue pour être **autoritative** (colonnes `derived_from ENUM('observed','manual')`,
`observed_delivery_count`, `is_primary`, `is_excluded_from_cogs_footprint`, SCD2
`effective_from/until`) — pas un cache de lecture. Seed = `INSERT…SELECT` depuis
`inv_deliveries` (status Active/Consumed, `supplier_fk IS NOT NULL`) GROUP BY
(supplier_fk, gl_account), puis UPDATE pour flagger `is_primary` (GL au plus fort
count par fournisseur, tie-break gl_account le plus bas). La page bascule
automatiquement du fallback `inv_deliveries` vers `ref_supplier_gls` une fois peuplée
(**aucun changement PHP requis** — le fallback devient code mort).

**Dry-run :** 99 lignes / 55 fournisseurs / 32 GLs ; 34 fournisseurs multi-GL.
**Pass admin nécessaire** sur les codes non-COGS qui remonteront (ex. OBI : 6631/6634
Taproom + 4500 R&D → `is_excluded_from_cogs_footprint=1` ; codes `IMM-*` immobilisation).

### Q2 — `country CHAR(2)` ISO → **confirmé, label dérivé en PHP** ✅
On stocke le code ISO-3166-1 alpha-2 et on dérive le libellé en PHP. **Pas** de
table `ref_countries` (le standard ISO EST le vocabulaire ; une lookup serait de la
dette pure). 134/134 actuellement `NULL` = NULL sémantique correct ("pas encore
renseigné"). Édition : `<input maxlength=2 pattern=[A-Z]{2}>` + validation deux temps.

### Q3 — 134 fournisseurs `active` → **correct, ne pas rétro-flagger** ✅
Règle de principe : `draft` ⟺ 0 livraison Active/Consumed **et** créé par ingest.
Les 134 ont un historique COGS → identité prouvée → `active` est juste. Le vrai
manque, ce sont les champs admin 100% NULL (`parser_key`, `country`, `vat_*`) → à
surfacer comme **score de complétude** dans la fiche, PAS un rollback en `draft`
(qui créerait une file de validation de 134 items vide de sens et risquerait de
bloquer l'ingest si du code gate sur `state='active'`).

### Q4 — Propositions manager → **nouvelle table `ref_supplier_proposals`** ✅
Migration `125_create_ref_supplier_proposals.sql` écrite (dry-run). On NE réutilise
PAS `mi_proposals_audit` : elle est MI-spécifique (14/22 colonnes nommées MI),
`schema_meta.corrections_policy='blocked'` (puits d'audit, pas surface de workflow),
et `validated_mi_id NOT NULL` forcerait des sentinelles `''`. Schéma propre :
`(id BIGINT UNSIGNED, supplier_fk INT UNSIGNED FK, field_name VARCHAR(64),
current_value TEXT, proposed_value TEXT, proposed_by INT UNSIGNED FK users,
proposed_at, status ENUM('pending','approved','rejected'), reviewed_by, reviewed_at,
review_note)` + ligne `schema_meta` (`table_class='audit'`, `corrections_policy='allowed'`,
`writer_script='web'`). Réutilisable pour toute proposition `ref_*` future.

### Q5 — Fusion de doublons → **différer l'UI, recette documentée** ✅
0 doublon connu aujourd'hui → bénéfice nul ; un script SQL one-shot battra l'endpoint
quand le premier vrai doublon apparaîtra. Quand on le construira (~12 statements,
~150 lignes PHP, 3 branches de collision) :

**FKs pointant vers `ref_suppliers` (à repointer du zombie vers le canonique) :**

| Table | Colonne | Note |
|---|---|---|
| `inv_deliveries` | `supplier_fk` | COGS-critique — snapshot avant |
| `doc_invoices` | `supplier_fk` | COGS-critique |
| `doc_delivery_notes` | `supplier_fk` | |
| `mi_proposals_audit` | `supplier_id` | |
| `ref_mi_invoicing_units` | `supplier_fk` | pack-size |
| `ref_supplier_aliases` | `supplier_id_fk` | UNIQUE `alias` → dédupliquer (INSERT IGNORE) |
| `ref_supplier_field_pins` | `supplier_fk` | merge pins, canonique gagne |
| `ref_supplier_gls` | `supplier_fk` | **ON DELETE CASCADE → repointer AVANT tout delete** ; collision possible sur `uk_supplier_gl` |

Séquence sûre : repointer les enfants d'abord → dédup aliases → merge pins/GLs
(gérer la collision `(supplier_fk,gl_account,effective_from)`) → NULLer le
`parser_key` du zombie (unicité) → zombie `commissioning_state='retired'` +
`is_active=0`. **Jamais DELETE** (préserve les FKs historiques).

---

## Ordre de construction recommandé (step 3)

1. `sf-pin-field`, `sf-update-field`, `sf-validate-supplier` (~40-60 lignes chacun, forte valeur).
2. Appliquer migration 126 (seed `ref_supplier_gls`) + pass admin `is_excluded_from_cogs_footprint`.
3. Appliquer migration 125 (`ref_supplier_proposals`) + endpoints propose/approve.
4. `sf-add-alias`.
5. Fusion de doublons — différée jusqu'au premier doublon réel.

---

## Migrations écrites (dry-run, non appliquées)

- `db/migrations/125_create_ref_supplier_proposals.sql`
- `db/migrations/126_seed_ref_supplier_gls_from_observed.sql`

**Application (après approbation) :**
```bash
cd /home/kluk/projects/maltyweb && rsync -avz --rsync-path="sudo rsync" -e "ssh -o BatchMode=yes" \
  db/migrations/125_create_ref_supplier_proposals.sql \
  db/migrations/126_seed_ref_supplier_gls_from_observed.sql \
  ubuntu@83.228.215.243:/var/www/maltytask/db/migrations/
ssh maltyweb 'sudo php /var/www/maltytask/scripts/migrate.php'
ssh maltyweb 'sudo php /var/www/maltytask/scripts/migrate.php --status'
```
