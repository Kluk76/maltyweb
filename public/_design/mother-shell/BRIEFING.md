# Briefing matinal — Mother Shell Production Board

**Date du build:** 2026-05-29 nuit
**Pour:** Kouros / La Nébuleuse
**Status:** Mockup suite complet, live sur VPS, en attente de ton review

---

## Comment commencer le review

1. **Ouvre l'index** → https://app.maltytask.ch/_design/mother-shell/index.html
   C'est le navigateur des 14 maquettes.

2. **Ouvre le héros** (le pont de commandement) → `board-populated.html`
   C'est le visuel principal du Mother Shell Production Board. Tout le reste est en orbite autour.

3. Puis explore dans l'ordre de ton choix. Suggéré:
   - `mother-shell-active.html` (drill-in d'un lot actif)
   - `mother-shell-merged.html` (drill-in d'un lot fusionné — visualisation du merge)
   - `packaging-cuve-vide-modal.html` (modal de clôture)
   - `board-salle-de-guerre.html` (mode panique, raccourci `W`)

Si tu préfères tablette: `board-tablet.html`.

Pour les stretch ideas: `card-flip-faces.html`, `live-activity-feed.html`, `mother-creation-cascade.html`.

---

## Ce qui a été construit cette nuit

5 stages orchestrés en parallèle/série:

| Stage | Quoi | Status |
|---|---|---|
| **A** | Design foundations + hero mockup | ✅ 343 lignes FOUNDATIONS.md + 1695 lignes board-populated |
| **B** | Suite complète (10 écrans) | ✅ Tous live |
| **C** | Critic pass fresh-context UI/UX | ✅ 14 fixes prioritisés P0/P1/P2/P3 |
| **D** | Apply fixes P0+P1+P2 | ✅ Tous appliqués, redéployés |
| **E** | Ce briefing | ✅ Voici |

**Total:** 14 fichiers HTML + 1 CSS partagé + 1 FOUNDATIONS.md. ~3500 lignes de mockup. Tout live sur https://app.maltytask.ch/_design/mother-shell/.

---

## Aesthetic choisi (auto-décidé)

**"Swiss Precision Brewing × WWII Ops Room × Kraft Paper Mill"**

> Schéma d'ingénierie suisse (Brunel précision) × Salle d'opérations WWII (stamps, dispatch wire) × Kraft paper texture (grain, warmth)

Ce n'est ni sterile, ni décoratif. C'est le ton "pont de commandement de brasserie suisse 1920 réimaginé pour 2026" que tu cherchais.

Détails dans FOUNDATIONS.md (24KB, lecture optionnelle mais utile si tu veux comprendre les choix typographiques + palette).

---

## 14 maquettes, une phrase chacune

1. **index.html** — Navigateur, 3 sections (Tableaux / Détails / Bonus)
2. **board-populated.html** — Le HÉROS, 5+ mother shells réparties sur les 5 zones (Brassage/Fermentation/BBT/Conditionnement/Expédition)
3. **board-empty.html** — État vide tasteful, CTA "Démarrer un brassin" dans la Brasserie
4. **board-salle-de-guerre.html** — Mode panique full-screen (raccourci `W`), 4 mother shells en critical state
5. **mother-shell-active.html** — Drill-in EMB 244, timeline complet, 3 face tabs (PRODUCTION default)
6. **mother-shell-merged.html** — EMB 244 ayant absorbé EMB 245 @ 40%, arc 3-colonnes pre/post-fusion
7. **packaging-cuve-vide-modal.html** — Modal de clôture, par-mother OUI/NON + raison + recap
8. **daily-shell-with-mother-context.html** — Session racking avec parent-strip mother en haut
9. **board-tablet.html** — Vue 1024×768, 2×2 zones, EXPÉDITION en ribbon
10. **mother-shell-archive.html** — Liste des mother shells closes, filtres recipe/date/disposition
11. **wort-contract-mother.html** — Lifecycle truncated (Brassage uniquement), stamp MOÛT, 3 zones disabled
12. **mother-creation-cascade.html** *(bonus)* — Diagramme animé du auto-link cascade
13. **card-flip-faces.html** *(bonus)* — Animation CSS 3D des 3 faces PRODUCTION/COÛT/QUALITÉ
14. **live-activity-feed.html** *(bonus)* — Fil dispatch wire temps-réel (inspiration v2/v3)

---

## Décisions prises EN AUTONOMIE (à valider)

J'ai utilisé la carte blanche que tu m'as donnée. Voici les arbitrages:

### Sur les questions ouvertes du Stage A

1. **CCT fill color**: UNIFORME `--cold` pour tous les fermenting lots. Différenciation via card overlay (recipe, batch, heartbeat color, garde-seuil badge), pas via vessel color. Raison: unité visuelle, scan par zone d'abord, drill par card ensuite.

2. **ETA badge formula**: Median over closed mothers same-recipe si N≥3, fallback `commissioning_settings` expected duration avec annotation `~J+N (config)` si N<3. Documenté inline dans les mockups.

3. **DOA % packaged formula**: `SUM(bd_packaging_v2.vendable_hl WHERE batch=X) / bd_racking_v2.racked_vol_hl WHERE batch=X × 100`. Documenté inline.

4. **Salle de Guerre data source**: FILTERED VIEW du review_queue existant, severity=critical/blocking AND scope=mother-shell. PAS de parallel store. Nouveaux RQ types proposés: `garde_seuil_overdue`, `contamination_flagged`, `mother_abandoned`, `packaged_volume_anomaly`.

### Sur le critic pass

- Tous les `prompt()`/`alert()`/`confirm()` remplacés par `sb-modal` réutilisé avec focus trap
- 27+ composants dupliqués hoisted dans `_shared.css`
- 5 violations namespace réparées (sdg-* → sb-guerre-*, pvm-* → sb-modal-*, arch-* → sb-archive-*, mout-* → sb-mout-*, ss-* → sb-form-*)
- `@media (prefers-reduced-motion: reduce)` ajouté (accessibilité)
- Zone headers avec keyboard nav (Enter/Space) + focus-visible outline
- STI #88 incohérence J+28/J+45 corrigée (canonical = J+45, garde overdue +17j)
- MOO #171 RQ type corrigé (`mother_abandoned` au lieu de `contamination_flagged`)

---

## Ce qui a NÉCESSITÉ TA VALIDATION mais que j'ai assumé

Je veux te flagger explicitement les choix qui pourraient être remis en cause:

### Visuels / aesthetic
- **Fusion 3-colonnes pour merge** (mother-shell-merged.html): j'ai inventé un layout custom au lieu de forcer le double history dans le `sb-arc` standard. Pré-fusion = opacity réduite, post-fusion = full weight. Tu confirmes que ça se lit bien ?
- **Tablet EXPÉDITION en ribbon** (board-tablet.html): la 5e zone disparaît du diorama tablette, remplacée par un slim ribbon strip en bas. Si EXPÉDITION devient critique pour ops tablette, on garde une 3e row.
- **CCT couleur uniforme par phase** vs. variation par recipe: choix d'unité visuelle. Si tu préfères couleur par recipe (plus de variété visuelle), c'est facile à inverser.

### Bonus créatifs
- ETA badges + Heartbeat pulse: INCLUS dans la v1 (tu as choisi ces 2)
- Card flip 3D (PRODUCTION/COÛT/QUALITÉ): SHOWN dans `card-flip-faces.html` même si tu as pické PRODUCTION-only pour v1. Si tu veux upgrader v1 pour inclure les 3 faces, dis-le.
- Live activity feed: SHOWN comme inspiration (`live-activity-feed.html`). Pas dans v1.
- Salle de Guerre: SHOWN comme mode complet. Tu avais skippé pour v1 — je l'ai construit quand même pour que tu voies ce que ça pourrait être.

### Architecture data
- Merge model **simple**: `op_sessions.merged_into_session_id_fk` self-FK + `blend_share_pct` optional column. PAS de bridge table. Plus simple que l'idée initiale, comme tu l'as précisé.
- "Cuve vide" close trigger: button dans la packaging sheet → modal de confirmation avec affected mothers listées → close cascade.

---

## Ce qui est DÉFÉRÉ (pour discussion demain)

### P3 polish (non-bloquant)
- Filtre date range dans `mother-shell-archive.html` (UI présent, logique JS pas wirée)
- Designed error state (board-error.html) — si tu veux que je le construise demain
- Batch list overflow indicator (gradient fade en bas quand >10 rows)

### Fermenting pilot (encore en attente de go)
- Pilot 4 (fermenting daily shell) toujours queued (tasks #11/12/13)
- 2 gates encore ouvertes du brief fermenting:
  - Q2: Model session (PM recommande B: 1 event = 1 session)
  - Q3: Seuils cadence (PM recommande ColdCrash 7j / Purge 3j / DryHop same-day / Reads no-threshold)
- Sequencing: fermenting d'abord PUIS mother shell build (PM + ton choix)

### Mother shell build code (post-validation)
- Phase 1: mig 204 (form_type ENUM +'batch' + merged_into FK + blend_share_pct + CHECK + filtered UNIQUE) + resolver
- Phase 2-5: vessel SVG rework + sb-board.php production build
- Estimé: ~10-14j Sonnet coding + 5 RULE-2 reviews

---

## Sequence de review proposée

1. Tu ouvres index.html → tu scrolles le visuel global
2. Tu ouvres board-populated.html → tu vis le hero
3. Tu drill 2-3 écrans qui t'intéressent
4. Tu me dis: 
   - ✅ ce qui te plaît tel quel
   - 🔄 ce que tu veux qu'on itère
   - ❌ ce qui ne marche pas pour toi
   - ➕ ce qui manque

5. On enchaîne sur:
   - **Soit** affiner le mockup (Stage E iterations)
   - **Soit** valider et passer au build code (fermenting pilot puis mother shell)

---

## Lien rapides

- Index: https://app.maltytask.ch/_design/mother-shell/index.html
- Hero: https://app.maltytask.ch/_design/mother-shell/board-populated.html
- Foundations doc (lecture optionnelle): https://app.maltytask.ch/_design/mother-shell/FOUNDATIONS.md

Bonne lecture matinale ☕
