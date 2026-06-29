# Manuel opérateur — Production & Brassage
### app.maltytask.ch — La Nébuleuse

> **Version** : juin 2026
> **Public** : Opérateurs de production et brasseurs
> **Support** : Contacter votre responsable ou un administrateur système

---

## À qui s'adresse ce manuel

Ce manuel est écrit pour les opérateurs de production et les brasseurs de La Nébuleuse. Il couvre tout ce dont vous avez besoin au quotidien pour :

- enregistrer un brassage (recette, ingrédients, volumes, densités)
- suivre la fermentation (mesures, houblonnage à froid, purges, cold crash)
- enregistrer les transferts entre cuves (CCT → BBT ou CCT)
- saisir les runs de conditionnement (bouteilles, canettes, fûts, cuves de service)
- réaliser l'inventaire mensuel des matières premières

**Ce manuel ne couvre pas la logistique** (Stock PF, expéditions, commandes, comptage produits finis, retours). Pour ces sujets, référez-vous au **Manuel Logistique**. Les opérateurs de production n'ont jamais besoin du Manuel Logistique pour les tâches couvertes ici.

**Ce manuel ne demande aucune connaissance informatique.** Si quelque chose ne fonctionne pas comme décrit, prévenez votre responsable — ne tentez pas de contourner.

Ce manuel est le compagnon long-format de la Visite guidée. La Visite guidée vous montre où aller ; ce manuel vous explique comment faire, pourquoi, et quoi éviter. Tout problème ou toute anomalie doit être signalé à votre responsable — ne contournez jamais une procédure sans accord explicite.

---

## Table des matières

1. [Introduction — Le modèle de données production](#1-introduction--le-modèle-de-données-production)
   - 1.1 L'identité permanente du lot
   - 1.2 Le volume cast-out : le chiffre le plus important
   - 1.3 Comment la production alimente les calculs
   - 1.4 La règle fondamentale : saisir au moment
2. [Accès à l'application — Premiers repères](#2-accès-à-lapplication--premiers-repères)
   - 2.1 Se connecter
   - 2.2 La barre de navigation
   - 2.3 La Visite guidée
3. [Le quotidien — saisir la production au fil de l'eau](#3-le-quotidien--saisir-la-production-au-fil-de-leau)
   - 3.1 Brassage
   - 3.2 Fermentation
   - 3.3 Transferts
   - 3.4 Conditionnement
4. [Comptage Matières Premières (Inventaire MP)](#4-comptage-matières-premières-inventaire-mp)
   - 4.1 Contexte et fréquence
   - 4.2 Comment fonctionne ce formulaire
   - 4.3 Étapes
   - 4.4 Compter palette par palette
   - 4.5 Vide ≠ zéro
   - 4.6 En cas de doute sur l'identité d'un ingrédient
5. [Bonnes pratiques et erreurs fréquentes](#5-bonnes-pratiques-et-erreurs-fréquentes)
   - 5.1 Les bonnes pratiques de l'opérateur de production
   - 5.2 Erreurs fréquentes et comment les éviter
   - 5.3 Que faire si quelque chose ne fonctionne pas
6. [Questions fréquentes](#6-questions-fréquentes)
- [Annexe A — Glossaire production](#annexe-a--glossaire-production)
- [Annexe B — Dispositions de conditionnement](#annexe-b--dispositions-de-conditionnement)
- [Annexe C — Références SKU : suffixes de format](#annexe-c--références-sku--suffixes-de-format)
- [Annexe D — Listes de contrôle (checklists)](#annexe-d--listes-de-contrôle-checklists)

---

## 1. Introduction — Le modèle de données production

### 1.1 L'identité permanente du lot

Tout commence par **la recette et le numéro de brassin**. Ces deux informations forment l'identité unique et permanente d'un lot. Chaque étape ultérieure — fermentation, transfert, conditionnement — se rattache à ce couple.

Un **brassin** correspond à un cycle de brassage : remplissage du brassin, filtration, ébullition, refroidissement, transfert en cuve de fermentation (CCT). Selon la taille de la recette et la capacité de la cuve de fermentation, un même lot (un même numéro de brassin dans la même CCT) peut nécessiter **1 à plusieurs brassins successifs**. Par exemple, un lot de Zepp de 125 HL nécessitera typiquement 4 brassins de 30 HL chacun. Dans ce cas, les 4 brassins partagent le même numéro de lot et la même CCT.

Cette logique a une conséquence pratique importante : **dans le formulaire de brassage, vous saisissez une ligne par brassin** dans la section "Déroulé du brassage". Le système additionne automatiquement les volumes.

Le numéro de brassin est séquentiel : vous l'attribuez vous-même (ex. "215" pour le deux cent quinzième brassin de la saison). Si vous re-soumettez le même couple recette + numéro, le système **met à jour** sans créer de doublon.

### 1.2 Le volume cast-out : le chiffre le plus important

Le **cast-out** est le volume de moût (bière non fermentée) transféré dans la cuve de fermentation (CCT) après refroidissement, à la fin de chaque brassin. C'est **le chiffre le plus important du formulaire de brassage**.

Pourquoi ? Parce qu'il conditionne :
- les calculs de **rendement** (combien de bière a été produite par rapport à ce qui était brassé)
- les calculs de **coûts de production** (le coût des ingrédients est rapporté au volume cast-out)
- la **traçabilité** de chaque lot de la cuve jusqu'aux produits finis

Ce volume est celui mesuré à froid, après le passage au refroidisseur — pas le volume théorique calculé ni le volume en chaudière. Il peut différer légèrement du volume Pfannevoll ou Kochwürze en raison des pertes à l'ébullition et au refroidissement.

> ⚠️ **Ne jamais laisser le cast-out vide.** Si vous n'avez pas mesuré exactement, donnez une estimation honnête et précisez-le en commentaire. Un cast-out vide fausse tous les calculs en aval.

### 1.3 Comment la production alimente les calculs

Chaque saisie que vous effectuez dans l'application contribue à deux grandes chaînes de calcul.

**Chaîne des coûts de production (COGS) :**

Les ingrédients saisis dans le formulaire de brassage, combinés aux volumes cast-out, permettent de calculer le coût réel de chaque lot brassé. Ces coûts se répercutent sur chaque produit fini grâce à la composition de la recette.

Les pertes de production à chaque étape contribuent aussi à ce calcul :
- **Perte CCT** (du cast-out au volume transféré en BBT) : liée à l'intensité en houblon. Les bières très houblonnées (dry-hop intense) ont naturellement plus de pertes en CCT car le houblon retient plus de bière. Ce n'est pas une inefficacité — c'est la nature de la recette.
- **Perte au transfert** (centrifugation, pasteurisation) : liée à l'équipement et au process.
- **Perte au conditionnement** (ligne d'embouteillage, remplissage) : liée au format et aux conditions de la session.

Signaler ces pertes précisément dans les formulaires permet à la direction de suivre les rendements réels et de détecter des dérives.

**Chaîne du stock produits finis (Stock PF) :**

Les runs de conditionnement alimentent directement le Stock PF de produits finis dans l'application. Un run non saisi = une production qui n'existe pas dans le système. Pour les mécaniques de calcul du Stock PF (formule, règles de comptage, ancrage, semaines de couverture), référez-vous au **Manuel Logistique**.

### 1.4 La règle fondamentale : saisir au moment

Chaque formulaire doit être rempli **au moment de l'opération, ou dans les deux heures qui suivent**. Les saisies "de mémoire" en fin de journée introduisent des erreurs : volume approximatif, heure incorrecte, N° de lot oublié, densité recopiée de la mauvaise mesure.

Les formulaires sont conçus pour être remplis en atelier, sur tablette. Ce manuel est la référence complète — ayez-le accessible pendant vos premières semaines.

---

## 2. Accès à l'application — Premiers repères

### 2.1 Se connecter

1. Sur votre tablette, ouvrez le navigateur (Chrome, Safari, Firefox — tous fonctionnent).
2. Dans la barre d'adresse, tapez **app.maltytask.ch** et appuyez sur Entrée.
3. Sur la page d'accueil, entrez votre **adresse e-mail** et votre **mot de passe**.
4. Appuyez sur **Se connecter**.

La page d'accueil vous amène directement à votre tableau de bord.

#### Si vous ne pouvez pas vous connecter

- Vérifiez que vous tapez bien l'adresse complète : `app.maltytask.ch` (sans "www").
- Vérifiez que le Wi-Fi de la tablette est actif.
- Si votre mot de passe est refusé → contactez votre responsable ou un administrateur pour réinitialiser.
- Ne créez pas de nouveau compte vous-même. Un administrateur doit créer votre compte.

#### Astuce : accès rapide depuis la tablette

Sur tablette, vous pouvez ajouter l'application à votre écran d'accueil pour l'ouvrir en un tap, comme une appli installée :

- **Sur iPad / iPhone :** ouvrez Safari → appuyez sur le bouton Partager → "Sur l'écran d'accueil".
- **Sur tablette Android :** ouvrez Chrome → appuyez sur les trois points → "Ajouter à l'écran d'accueil".

### 2.2 La barre de navigation

En haut de chaque page se trouve la **barre de navigation**. Elle donne accès à toutes les sections de l'application.

| Section | Ce que vous y trouvez | Pertinent pour... |
|---|---|---|
| **Brassage** | Saisie des informations d'un brassin | ✅ Production |
| **Fermentation** | Suivi des fermentations en cuve | ✅ Production |
| **Transferts** | Saisie des transferts de cuve à cuve | ✅ Production |
| **Conditionnement** | Saisie des runs de mise en emballage | ✅ Production |
| **Inventaire MP** | Comptage des stocks de matières premières | ✅ Production |
| **Expéditions** | Commandes, Stock PF, transferts inter-sites, retours | — domaine logistique, voir le Manuel Logistique |
| **Approvisionnement** | Réception des livraisons de matières premières | — domaine logistique, voir le Manuel Logistique |

En haut à droite se trouvent votre prénom et un menu avec : vos préférences, la Visite guidée, et la déconnexion.

### 2.3 La Visite guidée

À votre **premier lancement**, l'application vous propose une **Visite guidée** : un tour rapide des principales pages avec des explications contextuelles présentées sous forme de cartes.

La Visite guidée est une introduction. Ce manuel est le document de référence complet.

#### Relancer la Visite guidée

Si vous voulez la revoir à tout moment :

1. Appuyez sur votre prénom ou icône de compte (coin supérieur droit).
2. Choisissez **"Visite guidée"** dans le menu.
3. La visite redémarre depuis le début.

#### Partager la Visite guidée avec un nouvel opérateur

Chaque compte a sa propre Visite guidée. Un nouvel opérateur la verra automatiquement à son premier login. Pas besoin de configuration spéciale.

---

## 3. Le quotidien — saisir la production au fil de l'eau

**Règle générale :** saisir au moment où ça se passe, pas en fin de journée de mémoire. Une heure ou deux de décalage sont acceptables. Une journée de retard commence à introduire des erreurs.

**Logique de correction :**
- Le formulaire de **brassage** peut être re-soumis sans créer de doublon : re-soumettre le même couple recette + N° de brassin met à jour les informations. Une case de confirmation s'affiche pour éviter les re-soumissions accidentelles.
- Un **run de conditionnement** ou une **mesure de fermentation déjà soumis** → ne tentez pas de re-soumettre. Contactez votre responsable ou un administrateur pour correction.

Les formulaires sont conçus pour être remplis en atelier, sur tablette.

---

### 3.1 Brassage

**Où :** Menu → **Brassage**

Le formulaire de brassage enregistre toutes les informations d'un brassin. Il peut être soumis plusieurs fois pour le même lot sans créer de doublon : le système met à jour les informations existantes.

---

#### L'identité du brassin (section obligatoire)

| Champ | Ce qu'on saisit | Obligatoire ? |
|---|---|---|
| **Recette** | Choisir dans la liste déroulante des recettes actives | Oui |
| **N° de brassin** | Numéro séquentiel (texte libre, ex. "215") | Oui |
| **Date de brassage** | Date du jour par défaut — modifier si brassage rétroactif | Oui |

Le couple **recette + N° de brassin** est l'identité unique et permanente du lot. Tout ce qui suit — fermentation, transfert, conditionnement — se rattache à ce couple.

Si vous re-soumettez le même couple recette + N° de brassin, le système **met à jour** sans créer de doublon. Une case à cocher de confirmation s'affiche pour valider intentionnellement la mise à jour.

À la bas de la page, un tableau des **10 derniers brassins** soumis s'affiche — utile pour vérifier ce qui a déjà été saisi.

---

#### Section CIP

Cochez les cuves nettoyées avant le brassage : **CCT** (cuve de fermentation) et **YT** (cuve à levure). Cette section n'inclut pas les machines (la soutireuse et la centrifuge sont dans les formulaires Transferts et Conditionnement). Cocher le CIP ici confirme que les cuves étaient propres avant utilisation.

---

#### Section Cuve de fermentation (CCT)

Sélectionnez la **CCT** (cuve de fermentation cylindro-conique) dans laquelle le moût sera transféré après refroidissement.

La liste affiche les cuves actives avec leur capacité : "CCT N (X HL)".

> ⚠️ **Champ critique — à toujours renseigner.** Si la CCT n'est pas renseignée ici, le formulaire de fermentation sera **bloqué** (gate rouge) et vous ne pourrez pas enregistrer les mesures de fermentation. Si vous avez oublié : retournez dans Brassage, re-soumettez avec la CCT renseignée, puis vérifiez que Fermentation débloque bien la gate.

---

#### Section Levure

| Champ | Ce qu'on saisit | Notes |
|---|---|---|
| **Souche de levure** | Choisir dans la liste des souches connues | |
| **Génération** | Numéro de génération (ex. "3") | Génération = nombre de fois que cette levure a déjà été repiquée depuis son achat. Génération 1 = levure fraîche achetée ; génération 3 = levure récoltée et réutilisée 2 fois. |
| **Récolte de** | Lot source de la levure (ex. "ZEP 213") | Indiquer depuis quel brassin la levure a été récoltée |
| **Nouvelle souche** | Nom en texte libre si absente de la liste | À utiliser si une nouvelle souche est introduite pour la première fois |
| **YT n°** | Numéro du tank à levure (YT) utilisé | Nombre, minimum 1 |

---

#### Section Ingrédients

Cette section enregistre toutes les matières premières utilisées lors du brassage.

| Champ | Ce qu'on saisit | Notes |
|---|---|---|
| **Catégorie** | Chip à sélectionner (liste restreinte) | Voir note ci-dessous |
| **Ingrédient** | Recherche par type-ahead dans la base d'ingrédients | Taper les premières lettres |
| **Quantité** | Poids ou volume utilisé | |
| **Unité** | kg, g, L selon l'ingrédient | Vérifier l'unité |
| **N° de lot** | Numéro de lot du sac / conteneur | |

Appuyez sur **"Ajouter un ingrédient"** pour ajouter chaque ligne. Répétez pour chaque matière première.

**Catégories disponibles dans ce formulaire :**
- Malt
- Houblon (kettle / dry)
- Adjuvant
- Minéral
- Process

> **Note importante :** la levure ne s'enregistre pas ici — elle a sa propre section ci-dessus.
>
> Les agents de collage et de clarification (Nagardo, Clarex, Dehaze) doivent être saisis dans la catégorie **Process**, pas dans Adjuvant.

> N° de lot illisible sur un sac ? Laissez la case vide plutôt que d'inventer un numéro. Un N° de lot inventé est pire qu'une case vide pour la traçabilité.

---

#### Section Déroulé du brassage (sous-brassins)

C'est la section la plus importante du formulaire. Elle enregistre la progression de chaque brassin du lot.

**Une ligne = un cycle de brassage.** Si le lot nécessite plusieurs brassins successifs (multi-brassin), ajoutez une ligne par cycle en appuyant sur **"Ajouter un brassin"**. Les lignes entièrement vides sont ignorées par le système.

| Champ | Ce qu'on saisit | Notes |
|---|---|---|
| **Brassin** | Numéro du sous-brassin (1, 2, 3…) | Automatique |
| **Première trempe (°P)** | Densité du premier jus de filtration | En degrés Plato |
| **pH FW** | pH du premier jus (First Wort) | Optionnel |
| **Pfannevoll (°P)** | Densité en remplissage de chaudière | En degrés Plato |
| **Kochwürze (°P)** | Densité en fin d'ébullition | En degrés Plato |
| **Date début** | Date de début du brassin | |
| **Heure début** | Heure de début | |
| **Date fin** | Date de fin | |
| **Heure fin** | Heure de fin | |
| **Cast-out (HL)** | Volume de moût transféré en CCT après refroidissement | ⚠️ Ne jamais laisser vide |
| **Dilution (HL)** | Volume d'eau ajouté au refroidissement | Optionnel — indiquer uniquement si dilution effective |
| **OG (°P)** | Densité à l'entrée en fermentation (après refroidissement) | ⚠️ Ne jamais laisser vide |
| **pH** | pH à l'entrée en fermentation | Optionnel |

En bas du tableau, un pied de page affiche le **total cast-out** calculé automatiquement : "Total : X.X HL".

> ⚠️ **Avertissement heure :** le formulaire affiche un avertissement doux si l'heure de fin d'un sous-brassin précède son heure de début. Ce n'est pas bloquant — vérifiez et corrigez si c'est une erreur de saisie.

---

#### Densités en °Plato : récapitulatif

Les degrés Plato (°P) mesurent la concentration en sucre du moût ou de la bière à différentes étapes. Plus la valeur est élevée, plus le moût est concentré en sucre (et plus la bière sera alcoolisée).

| Mesure | Quand la prendre | Signification |
|---|---|---|
| **Première trempe** | Premier jus qui sort du brassin filtré | Indique la qualité de la filtration et la concentration initiale |
| **Pfannevoll** | Au remplissage complet de la chaudière | Concentration avant ébullition |
| **Kochwürze** | En fin d'ébullition | Concentration après concentration par ébullition |
| **OG (Original Gravity)** | Au refroidissement, à l'entrée en CCT | ⚠️ La plus importante — c'est la densité initiale de fermentation, le point de départ du suivi de fermentation |

> Ne jamais laisser l'OG vide. C'est la valeur de référence pour toute la fermentation.

---

#### Section Commentaires brassage

Champ texte libre. Notez ici tout ce qui sort de l'ordinaire : incident technique, ajustement de recette, observation qualitative.

---

#### Soumission

Appuyez sur **"Enregistrer le brassin →"** en bas du formulaire. Un message de confirmation s'affiche.

Si le formulaire ne se soumet pas, vérifiez les champs signalés en rouge — ce sont les champs obligatoires manquants.

Pour **corriger un brassage déjà soumis** : re-soumettez le même couple recette + N° avec les valeurs corrigées. Une case de confirmation s'affiche. Pour les corrections de données clés (volume, densité), préférez passer par un manager ou admin si le lot est déjà en fermentation avancée.

---

#### Garde-fous et avertissements

L'application peut afficher des **avertissements** (en jaune ou orange) si une valeur semble hors plage normale. Ces avertissements **ne bloquent pas** la soumission.

- Si vous voyez un avertissement et que la valeur est correcte → soumettez et signalez à votre responsable.
- Si vous avez un doute → vérifiez la valeur avant de soumettre.

---

### 3.2 Fermentation

**Où :** Menu → **Fermentation**

Le formulaire de fermentation enregistre l'évolution d'un lot pendant toute sa garde en cuve : mesures de densité, pH, température, houblonnage à froid, purges, et le cold crash final.

**Toutes les valeurs sont acceptées sans blocage** — des avertissements sont affichés si une valeur est hors plage typique, mais jamais bloquants.

---

#### Sous-titre et contexte

Ce formulaire couvre :
- Les **mesures régulières** : densité (°Plato), pH, température
- Le **houblonnage à froid** (dry-hop — terme technique) : ajout de houblon directement en cuve froide après fermentation
- Les **purges** : soutirage de levures mortes ou de sédiments depuis le fond de la CCT
- Le **Cold Crash** : refroidissement forcé qui termine la fermentation et débloque le passage en transfert

---

#### Trouver un lot

La page affiche les **cartes de lots éligibles** : lots en CCT avec fermentation démarrée. Chaque carte affiche la bière, le numéro de brassin, et la CCT.

Si un lot attendu n'apparaît pas dans la liste :
1. Vérifiez que le **brassage a bien été soumis** dans le formulaire Brassage.
2. Vérifiez que la **CCT est renseignée** dans ce formulaire de brassage (voir §3.1 — section CCT).

**Manager / Admin uniquement :** la case "Choix Hors Process" affiche **tous** les lots en CCT ou BBT, même ceux hors du flux normal. Réservé aux cas exceptionnels.

---

#### La porte d'accès (Gate CCT)

Avant de pouvoir soumettre une mesure de fermentation, le système vérifie que la CCT du lot est bien renseignée dans le formulaire de brassage.

| État de la gate | Signification | Action |
|---|---|---|
| ✅ **"CCT N (saisie brewday)"** | Gate ouverte — formulaire actif | Saisir les mesures normalement |
| 🚫 **"CCT non renseignée dans la saisie brewday — corriger avant de démarrer"** | Gate rouge — soumission DÉSACTIVÉE | Retourner dans Brassage, ajouter la CCT, re-soumettre |
| 🚫 **"Aucune CCT trouvée pour ce brassin"** | Gate rouge — soumission DÉSACTIVÉE | Vérifier que le brassage existe et contient une CCT |

> ⚠️ Si la gate est rouge, le bouton Soumettre est grisé — vous ne pouvez pas enregistrer de mesures. La seule solution est de corriger la CCT dans le formulaire Brassage.

**Gate 2 — Garde levure (informatif seulement, jamais bloquant) :**

Le système affiche également une information sur la date à partir de laquelle le Cold Crash est possible, selon la durée de garde minimale de la souche utilisée : "ColdCrash possible ≥ [date] ([Souche], garde min N j)". C'est uniquement informatif — il ne bloque pas la saisie.

---

#### Choisir le type d'événement

Sur la page d'accueil de Fermentation, sélectionnez le type d'événement dans la liste déroulante :

| Type d'événement | Quand l'utiliser |
|---|---|
| **Mesures densité / pH / temp** | À chaque relevé régulier en cuve |
| **Houblonnage à froid** | Lors de chaque ajout de houblon en cuve froide |
| **Purge** | Lors des purges de levure (fond de cuve) |

> **Important :** le Cold Crash n'est **pas** un type d'événement séparé dans la liste. C'est une **case à cocher** à l'intérieur du formulaire de mesures — voir ci-dessous.

---

#### Saisir un relevé de mesures (type : Mesures densité / pH / temp)

1. Sélectionnez le lot dans les cartes.
2. Choisissez "Mesures densité / pH / temp".
3. Remplissez le formulaire :

| Champ | Plages typiques | Notes |
|---|---|---|
| **Date** | Date du relevé (défaut : aujourd'hui) | Modifier si relevé rétroactif |
| **Densité (°Plato)** | 0 à 30°P — OG typique 10–20°P, FG 0,5–5°P | Mesurée au réfractomètre ou densimètre |
| **pH** | 2 à 8 — Pale Ale typique 4,1–4,6 | Optionnel si non mesuré |
| **Température (°C)** | -5 à 40°C — Fermentation 16–22°C, Cold crash 0–4°C | Température de la cuve |

4. **Case Cold Crash :** "Cocher pour enregistrer le refroidissement final. Cette action termine la session de fermentation et débloque le passage en garde / transfert."

> ⚠️ **Attention critique — Cold Crash.** Cochez cette case dès que le refroidissement final commence. Un lot dont le Cold Crash n'est **pas coché n'apparaît pas** dans la liste de la page Transferts. C'est la première chose à vérifier si un lot "prêt" n'apparaît pas dans Transferts.

5. Si Cold Crash coché : un champ **Commentaire cold crash** apparaît — notez tout ce qui est pertinent (date réelle si rétroactif, température cible atteinte, observations).

6. **Observations générales** : champ texte libre pour tout commentaire sur la fermentation.

7. Appuyez sur **"Enregistrer →"**.

> Saisissez chaque relevé même si les mesures sont rapprochées dans le temps. Le système construit automatiquement la courbe de fermentation. Ne "moyennez" pas deux relevés pour n'en saisir qu'un.

---

#### Saisir un houblonnage à froid (type : Houblonnage à froid)

Le houblonnage à froid consiste à ajouter du houblon directement dans la cuve froide après fermentation, pour apporter des arômes sans amertume supplémentaire.

1. Sélectionnez le lot.
2. Choisissez "Houblonnage à froid".
3. Remplissez :

| Champ | Ce qu'on saisit |
|---|---|
| **Température du houblonnage (°C)** | Optionnel — température de la cuve au moment de l'ajout |
| **Ingrédient** | Variété de houblon (recherche type-ahead) |
| **Quantité** | Poids en kg |
| **Unité** | kg (ou g pour petites quantités) |
| **N° de lot** | N° de lot du houblon utilisé |

Appuyez sur **"Ajouter une addition"** pour chaque variété de houblon ajoutée. La catégorie est dérivée automatiquement — pas besoin de la choisir.

4. **Observations générales** : champ texte libre.
5. Appuyez sur **"Enregistrer →"**.

---

#### Saisir une purge (type : Purge)

La purge consiste à soutirer les levures mortes et sédiments qui s'accumulent au fond de la CCT pendant la fermentation.

1. Sélectionnez le lot.
2. Choisissez "Purge".
3. Remplissez :

| Champ | Ce qu'on saisit |
|---|---|
| **Pression cuve (bar)** | Optionnel — pression de la cuve au moment de la purge |
| **Commentaire purge** | Observations, volume purgé si mesuré |

4. **Observations générales** : champ texte libre.
5. Appuyez sur **"Enregistrer →"**.

---

#### Garde-fous

L'application affiche des avertissements pour les valeurs hors plage — densité finale anormalement haute, température trop basse, etc. Ces avertissements **ne bloquent pas** la soumission.

Le bouton Soumettre est **désactivé (grisé)** uniquement si la gate CCT est rouge (CCT non renseignée dans le brassage).

---

### 3.3 Transferts

**Où :** Menu → **Transferts**

> **Note de nomenclature :** le terme technique international est "racking" — dans l'interface et dans ce manuel, c'est simplement la page **Transferts**.

Cette page gère les transferts de bière entre cuves : typiquement de la CCT (cuve de fermentation) vers la BBT (Bright Beer Tank, cuve de bière filtrée), mais aussi CCT → CCT ou CCT → YT selon les besoins.

**Toutes les mesures sont acceptées — des avertissements sont affichés si une valeur est hors plage typique, jamais bloquants.**

---

#### Contexte et lots éligibles

La page affiche les **cartes de lots éligibles** : lots en CCT pour lesquels le Cold Crash a été enregistré et dont la durée de garde minimale est atteinte.

Chaque carte affiche :
- Type et numéro de la cuve source (CCT N)
- Bière et numéro de brassin
- "Cold crash : [date] (N jours)"
- "Garde : N jours minimum"

**Si un lot attendu n'apparaît pas :**
1. Vérifiez que le **Cold Crash est coché** dans le formulaire Fermentation pour ce lot.
2. Vérifiez que la **durée de garde minimale** est atteinte depuis le cold crash.
3. Si une exception est justifiée (urgence, lot prêt avant la garde minimale) → demandez à un manager d'activer le mode Hors Process. Ne contournez pas vous-même.

L'état vide explique les conditions d'éligibilité.

---

#### Section CIP

Cochez les équipements nettoyés avant le transfert :

**Machines** (au moins une est obligatoire) :
- Centrifuge
- KZE (Kerzenfilter-Zentrifuge — filtre de pasteurisation flash)
- Pompe

**Cuve destination** :
- BBT / CCT / YT

> ⚠️ **Au moins un équipement machine (centrifuge, KZE ou pompe) doit être renseigné.** C'est ce choix qui détermine le type de transfert enregistré. Sans machine cochée, la soumission est bloquée.

> Si la cuve destination est vide (résiduel = 0 HL), le CIP de la cuve destination est également requis.

---

#### Pasteurisation flash (KZE)

Cette section est **masquée** jusqu'à ce que KZE soit coché dans la section CIP.

Quand KZE est sélectionné, deux champs apparaissent :

| Champ | Ce qu'on saisit |
|---|---|
| **Target PU** | Unités de pasteurisation cibles (objectif du process) |
| **Moyenne PU réalisé** | Unités de pasteurisation effectivement mesurées pendant le transfert |

Les PU (Pasteurisation Units, ou unités de pasteurisation) sont la mesure de l'efficacité du traitement thermique. Chaque bière a un objectif défini — votre responsable vous communique la cible.

---

#### Sélection du lot source

Appuyez sur la carte du lot à transférer. Les cartes affichent uniquement les lots éligibles (Cold Crash + garde atteinte).

**Manager / Admin uniquement :** toggle "Choix Hors Process" — bypasse la garde minimale et affiche tous les lots en CCT/BBT. Une justification peut être saisie (optionnelle).

---

#### Opération de transfert

| Champ | Ce qu'on saisit |
|---|---|
| **Date transfert** | Date réelle du transfert (rétroactif accepté) |
| **Heure début** | Heure de début du transfert |
| **Heure fin** | Heure de fin du transfert |

---

#### Tank destination

1. Choisissez le **type de destination** : BBT, CCT ou YT.
2. Choisissez le **numéro** de la cuve de destination.

**Blending (subsection — BBT uniquement) :**

Quand la destination est une BBT et que la bière transférée a déjà du stock dans d'autres BBTs, des cartes de blending apparaissent. Le blending consiste à mélanger le reste d'un lot précédent dans la BBT de destination avec le nouveau lot entrant.

> ⚠️ **Règle fondamentale du blending :** on ne mélange jamais deux bières différentes. Seule la même recette peut être blendée dans la même BBT. Si une BBT contient des restes d'une recette différente, il ne doit pas y avoir de blending.

Une case "BBT vide — ignorer le résiduel suivi" est disponible si la BBT était vide mais que le système affiche encore un résiduel théorique.

---

#### Mesures

| Champ | Ce qu'on saisit | Notes |
|---|---|---|
| **Relevé compteur début (HL)** | Lecture du compteur de débit en début de transfert | |
| **Relevé compteur fin (HL)** | Lecture du compteur de débit en fin de transfert | |
| **Volume transféré (HL)** | Calculé automatiquement depuis les relevés compteur | Vérifiez la cohérence |
| **Volume résiduel en cuve (HL)** | Volume restant dans la CCT source après transfert | Saisir 0 si cuve complètement vidée — ne pas laisser vide |
| **Volume résultant en cuve destination (HL)** | Calculé automatiquement | |
| **CO₂ (g/L)** | Teneur en CO₂ mesurée dans la cuve | |
| **O₂ (ppb)** | Teneur en oxygène mesurée | |
| **Pression destination (bar)** | Pression dans la BBT de destination | |
| **Turbidité (NTU)** | Mesure de turbidité de la bière transférée | Optionnel |
| **Centri rincée ?** | Oui / Non | Confirmer si la centrifuge a été rincée après le transfert |
| **Safety CIP effectué ?** | Oui / Non | Confirmer si le CIP de sécurité a été réalisé |

> Ne pas laisser "Volume résiduel en cuve" vide. Saisir 0 si la cuve source est complètement vide.

---

#### Pertes (section repliable)

Pour afficher cette section, appuyez sur **"Des pertes à signaler ?"**.

| Champ | Ce qu'on saisit |
|---|---|
| **Perte cuve départ (HL)** | Volume perdu dans la cuve source (ex. pertes lors du vidage) |
| **Perte cuve arrivée (HL)** | Volume perdu dans la cuve de destination |
| **Cause** | Produit / Machine / Humain |
| **Bilan volumes (calculé)** | Calculé automatiquement |
| **Détails / explication** | Texte libre — max 500 caractères |

> **Règle importante :** les pertes standard liées au process (pertes de centrifugation, volumes morts en cuve) sont calculées automatiquement par le système. **Ne les saisissez pas ici.** Cette section est réservée aux pertes **exceptionnelles** : incident technique, casse, déversement accidentel, problème de process inhabituel.

> Validation : si vous saisissez une perte de volume mais laissez la cause vide, le formulaire bloque la soumission : "Une perte de volume a été saisie mais la cause est absente."

---

#### Transfert interrompu (section repliable)

Pour afficher cette section, appuyez sur **"Le transfert a été interrompu"**.

| Champ | Ce qu'on saisit |
|---|---|
| **Raison de l'interruption** | Texte libre — obligatoire si section cochée |
| **BBT encore propre ?** | "Oui reste propre" / "Non à nettoyer" — affiché si volume transféré = 0 |

> Validation : "Un transfert interrompu nécessite une raison." — vous devez expliquer pourquoi le transfert a été stoppé.

---

#### Commentaires libres

Champ texte libre pour tout commentaire sur le transfert.

---

#### Soumission

Appuyez sur **"Enregistrer le transfert →"**. Si des champs obligatoires sont manquants, des messages de validation s'affichent en rouge.

**Principaux messages de validation :**
- "Au moins un équipement CIP (centri / KZE / pompe) doit être renseigné — il détermine le type de transfert."
- "CIP cuve destination requis (résiduel = 0)."
- "Un transfert interrompu nécessite une raison."
- "Une perte de volume a été saisie mais la cause est absente."

---

#### Mode Hors process

> **Réservé aux managers et admins uniquement.**
>
> Ce mode déverrouille les lots normalement inéligibles (garde non atteinte, Cold Crash absent) et exige une raison écrite. Il est utilisé pour gérer les cas exceptionnels justifiés.
>
> Si vous pensez avoir besoin de ce mode, **contactez votre responsable**. Ne tentez pas de contourner.

---

### 3.4 Conditionnement

**Où :** Menu → **Conditionnement**

Le formulaire de conditionnement enregistre chaque session de mise en emballage : bouteilles 33cl, canettes 50cl ou 33cl, fûts 20L, cuves de service.

**C'est ce formulaire qui alimente directement le Stock PF.** Un run de conditionnement saisi ici augmente immédiatement le stock de produits finis du montant produit. Pour les mécaniques de calcul du Stock PF, référez-vous au Manuel Logistique.

---

#### Contexte

Les lots éligibles sont ceux en BBT ou CCT disponibles depuis un certain nombre de jours après le dernier soutirage (transfert). Ce délai garantit que la bière est bien stabilisée avant mise en emballage.

---

#### Section CIP

| Équipement | Obligatoire ? |
|---|---|
| **Soutireuse (filler)** | Toujours obligatoire |
| **KZE flash** | Optionnel — si pasteurisation flash avant remplissage |

---

#### Sélection du lot source

Les cartes de lots éligibles affichent :
- Type et numéro de la cuve (BBT ou CCT)
- Capacité en HL
- Bière et numéro de brassin
- Volume restant simulé (HL)
- "raclé X HL" (volume déjà conditionné depuis ce lot)
- "soutirée [date]" (date du dernier transfert en BBT)

**Manager / Admin uniquement :** toggle "Choix Hors Process" — bypasse le délai minimum après soutirage et affiche tous les lots éligibles. Justification optionnelle.

**État vide :** "Aucun lot éligible (soutirage ≥ N jour). Vérifier que les soutirages récents sont enregistrés dans Saisie Soutirage (Transferts)." Si aucun lot n'apparaît, vérifiez que les transferts BBT récents ont bien été enregistrés.

**Toggle "Réassigner une cuve" (tous utilisateurs — badge "Cuv service") :** ce mode permet de réaffecter une cuve de service déjà remplie à un nouveau client, **sans prélever de volume**. À utiliser uniquement pour changer le client destinataire d'une cuve de service existante — pas pour enregistrer un nouveau conditionnement.

---

#### Date de la session

| Champ | Ce qu'on saisit |
|---|---|
| **Date de conditionnement** | Défaut : aujourd'hui. Les dates passées sont acceptées (session rétroactive). |

---

#### Relevé in-tank CO₂/O₂ (AVANT soutirage)

> ⚠️ **Champ critique — à saisir AVANT de commencer le remplissage.**

Ces mesures sont prises sur la cuve de bière **avant** le début de la session de remplissage :

| Champ | Exemple | Notes |
|---|---|---|
| **CO₂ in-tank (g/L)** | 4,8 | Teneur en CO₂ de la cuve avant soutirage |
| **O₂ in-tank (ppb)** | 25 | Teneur en oxygène de la cuve avant soutirage |

**Une seule paire de mesures par lot et par jour.** Si vous conditionnez plusieurs formats du même lot le même jour, ces valeurs sont partagées automatiquement entre tous les formats. Vous n'avez à les saisir qu'une seule fois.

**Bannière d'auto-remplissage :** si une lecture in-tank a déjà été saisie aujourd'hui pour ce lot, les champs s'affichent verrouillés ("champs verrouillés") avec les valeurs existantes — pas besoin de les re-saisir.

**Manager / Admin :** peut déverrouiller ces champs pour correction si une erreur a été saisie.

> **Validation :** le formulaire requiert ces valeurs avant d'autoriser la soumission. Ne pas prendre les mesures avant de commencer = impossible de soumettre à la fin du run. Prenez-les dès l'ouverture de la cuve.

---

#### Types de runs et mosaïque SKU

**Types de runs disponibles :**

| Type de run | Description |
|---|---|
| **Bouteille 33cl** | Mise en bouteille de 33 centilitres |
| **Canette 50cl** | Mise en canette de 50 centilitres |
| **Canette 33cl** | Mise en canette de 33 centilitres |
| **Fût 20L** | Remplissage de fûts de 20 litres |
| **Cuve de service** | Remplissage d'une cuve de service (taproom, festival) |

Quand vous sélectionnez un lot source, une **mosaïque SKU** apparaît : les tuiles cliquables de tous les formats actifs pour cette recette. Cliquez sur la tuile correspondant au format que vous conditionnez — cela pré-sélectionne le format.

**Sélecteur de suffixe de format :**

| Suffixe | Ce que ça signifie |
|---|---|
| — principal (pas de suffixe) — | Format principal de la session |
| **4** | Carton 6×4 (24 bouteilles) |
| **B** | Box 24 (canettes ou bouteilles) |
| **F** | Fût 20L |
| **V** | Cuve de service |
| **C** | Canette unitaire |
| **BU** | Bouteille unitaire |
| **CU** | Canette unitaire (format alternatif) |
| **X** | Cage / vrac bouteilles |

---

#### Champs de quantité

| Champ | Ce qu'on saisit |
|---|---|
| **Unités produites** | Nombre total d'unités sorties de la ligne de remplissage (vendables + non vendables) |
| **Unités non vendables** | Unités produites mais hors circuit de vente (invendables, QA…) |
| **Analyses QA** | Unités prélevées pour analyses qualité en cours de session |
| **Bibliothèque QA** | Unités archivées pour la bibliothèque qualité (suivi dans le temps) |
| **Objectif (HL)** | Volume cible de la session (optionnel — aide au suivi du run) |

Le volume vendable est calculé automatiquement : unités produites − unités non vendables.

---

#### Champs de pertes selon le type de run

**Pour les runs bouteille et canette :**

| Champ | Ce qu'on saisit |
|---|---|
| Pertes non bouchées | Unités remplies mais jamais capsulées / fermées |
| Demi-remplies | Unités à moitié remplies |
| Non taxées pleines | Unités pleines exclues de la taxe bière |
| 4-pack bouteille / canette | Perte d'emballages 4-pack |
| Emballage bouteille / canette | Perte d'emballages génériques |
| Étiquette bouteille | Perte d'étiquettes |
| Capsule couronne | Perte de capsules |
| Couvercle canette | Perte de couvercles de canette |
| Contenant bouteille / canette | Perte de contenants (bouteilles ou canettes vides) |

**Pour les runs fût et cuve de service :**

| Champ | Ce qu'on saisit |
|---|---|
| Perte liquide cuve (L) | Volume de bière perdu dans la cuve (résidus, rinçage) |
| Taproom keg (L) | Volume mis directement en service taproom sans passer par le stock vendable |
| Perte collier keg | Perte de colliers de fûts |
| Perte sauvegarde keg | Perte lors de la fermeture de sauvegarde du fût |

---

#### White label

Cochez la case **"White label"** et entrez le **nom du client** si la production est conditionnée sous le label d'un client tiers (brassage en marque blanche). Cette option modifie l'attribution de la production pour la traçabilité.

---

#### Cuve de service (run type = Cuve de service)

Champs supplémentaires pour les cuves de service :

| Champ | Ce qu'on saisit |
|---|---|
| **Client** | Liste des venues et festivals — choisir le client destinataire |
| **Liner client** | Type de liner fourni par le client |
| **Liner transport** | Type de liner de transport |
| **Cuve réutilisée** | Choisir parmi les cuves récentes non encore réutilisées |

En mode **Réassigner** : réaffecter la cuve à un nouveau client sans prélèvement de volume.

---

#### Formats parallèles

Un même lot peut produire **plusieurs formats différents dans la même journée** (ex. : cartons 6×4 et bouteilles unitaires du même lot, conditionnés le même jour). Dans ce cas :

1. La première carte de format est le **format principal** — il porte sa propre quantité.
2. Appuyez sur **"+ Ajouter un format parallèle"** pour chaque format supplémentaire.
3. Chaque ligne parallèle porte **uniquement sa propre quantité**.

> ⚠️ **Erreur fréquente à éviter.** Ne saisissez jamais la quantité totale sur la ligne principale ET les quantités détaillées sur les lignes parallèles — cela doublerait la production dans le Stock PF. Chaque ligne ne porte que ce qui a été produit dans ce format spécifique.

> **Validation :** "Exactement un format principal (main) requis." — vous devez avoir exactement une ligne sans suffixe de format.

> **Règle absolue :** une session = une bière. Ne mélangez jamais deux bières différentes dans la même session de conditionnement. Si vous conditionnez deux bières le même jour, faites deux sessions séparées.

---

#### Cage / coffret (format X)

Pour les runs en cage (vrac de bouteilles non emballées individuellement) :

1. Choisissez le format **X** (cage / vrac bouteilles) dans le sélecteur de suffixe.
2. Saisissez le **nombre de bouteilles** (pas le nombre de cages). Exemple : 3 cages de 120 bouteilles = 360 bouteilles.
3. Si le SKU cage n'existe pas encore pour cette bière → l'application affiche une erreur. Contactez un administrateur pour qu'il crée le SKU.

---

#### Mesures CO₂/O₂ en cours de soutirage (in-filling)

Cette section permet d'enregistrer jusqu'à **20 relevés de CO₂ (g/L) et O₂ (ppb)** pris **sur les unités en cours de remplissage** pendant le run.

Ces mesures sont différentes des mesures in-tank :
- **In-tank** = mesure sur la cuve avant de commencer (une seule fois par lot/jour)
- **In-filling** = mesures prises sur les unités pendant le remplissage (contrôle qualité continu)

Les unités prélevées pour ces mesures sont comptabilisées dans les "Analyses QA". Saisissez un relevé à intervalles réguliers selon le protocole QA de votre brasserie.

---

#### DLC / BBD

Champ optionnel : mois de la **Date Limite de Consommation** (DLC) / **Best Before Date** (BBD) à indiquer sur le produit. Format : mois (AAAA-MM).

---

#### Commentaires libres

Champ texte libre pour tout commentaire sur la session.

---

#### Soumission et vérification

Appuyez sur **"Enregistrer le conditionnement →"**. Ce bouton est désactivé tant qu'un lot source n'est pas sélectionné et que les mesures CO₂/O₂ in-tank ne sont pas renseignées.

Après soumission :
1. Vérifiez que la bière et le format affichés correspondent bien à ce que vous avez produit.
2. Vérifiez que la quantité correspond à la production réelle.
3. Vérifiez que les dispositions sont correctes.

Si vous avez soumis un run en erreur → contactez un manager ou admin immédiatement. Ne re-soumettez pas.

**Principaux messages de validation :**
- "Au moins un format de conditionnement est requis."
- "Exactement un format principal (main) requis."
- "Relevé in-tank CO₂/O₂ requis avant soutirage."
- Erreur cage SKU manquant → admin doit créer le SKU.

---

## 4. Comptage Matières Premières (Inventaire MP)

**Où :** Menu → **Inventaire MP**

### 4.1 Contexte et fréquence

L'**Inventaire Matières Premières** est le comptage physique mensuel des stocks de matières premières : malts, houblons, adjuvants, minéraux, emballages, produits de nettoyage, et tout autre consommable de production.

Ces données alimentent les calculs de coûts de production : sans un comptage précis et régulier, les coûts calculés de chaque lot brassé sont faux, ce qui fausse les marges reportées à la direction.

**Fréquence recommandée :**
- Au moins **une fois par mois**, idéalement en fin de mois.
- Les ingrédients critiques ou en faible stock peuvent justifier un **comptage hebdomadaire**.

> **Lien avec la logistique :** les quantités réceptionnées par la logistique (approvisionnement / réception des livraisons) contribuent aux stocks de matières premières de l'autre côté — pour la réception des livraisons, voir le Manuel Logistique.

---

### 4.2 Comment fonctionne ce formulaire (ligne par ligne, pas de bouton global)

Ce formulaire est **fondamentalement différent** du comptage Stock PF de la logistique.

**Différence clé :** chaque appui sur **"+ Ajouter"** sauvegarde **immédiatement** cette ligne dans le système. Il n'y a **pas de bouton "Soumettre" global**. Vous pouvez :
- quitter et revenir — ce que vous avez saisi est conservé
- compter dans n'importe quel ordre
- faire des pauses pendant le comptage
- saisir plusieurs lignes pour le même ingrédient (différentes palettes / lots)

Les lignes multiples pour un même ingrédient s'additionnent automatiquement et s'affichent comme sous-total dans le registre.

Ce n'est **pas** un formulaire où vous saisissez un total global par ingrédient en une seule ligne. C'est un registre **palette par palette, conteneur par conteneur**.

---

### 4.3 Étapes

1. **Ouvrez** Inventaire MP depuis le menu.

2. **Vérifiez la période** en haut du formulaire (mois AAAA-MM).
   - Par défaut : le mois en cours.
   - Pour compter un autre mois : modifier la période si vous êtes habilité. Si non → demander à votre responsable.
   - Appuyez sur **"Afficher ce mois →"** pour charger le registre du mois.
   - Un statut s'affiche : "✓ N ingrédient(s) saisi(s) pour [période]."

3. **Section "Ajouter une palette"** :
   - Tapez les premières lettres du nom de l'ingrédient dans le champ **"Rechercher un ingrédient…"** (type-ahead automatique).
   - Choisissez l'ingrédient dans la liste filtrée.
   - Une fois sélectionné, le nom, l'unité et un bouton "✕ Changer d'ingrédient" s'affichent.

4. **Saisissez la quantité** comptée pour cette palette / ce conteneur :
   - Minimum : 0
   - Précision : jusqu'à 3 décimales (0,001)
   - L'unité s'affiche automatiquement selon l'ingrédient (kg, g, L, unités…)

5. Appuyez sur **"+ Ajouter"** — la ligne est sauvegardée immédiatement. Une confirmation brève s'affiche.

6. **Répétez** pour chaque palette / conteneur, en changeant d'ingrédient si nécessaire.

7. **Registre "Saisies du mois"** (en bas de page) :
   - Les lignes sont groupées par ingrédient avec un sous-total automatique.
   - Chaque ligne affiche un bouton **"✕"** pour la supprimer si vous avez fait une erreur de saisie.
   - Un **"Total général"** est affiché en bas.
   - Lien **"← Retour"** pour revenir à la navigation principale.

---

### 4.4 Compter palette par palette

Pour les gros stocks (palettes de malt, cartons de houblon), comptez **sac par sac, palette par palette**, en ajoutant une ligne pour chaque unité de comptage.

Les estimations "à la louche" (ex. "environ 500 kg sur cette palette") introduisent des erreurs dans les calculs de coûts qui peuvent se répercuter sur les marges reportées à la direction.

**Cas des gros sacs entamés :** pesez si possible. Si vous ne pouvez pas peser, estimez honnêtement et précisez-le en commentaire à votre responsable.

---

### 4.5 Vide ≠ zéro (règle importante)

Si un ingrédient est **épuisé** (stock à zéro) :
1. Recherchez-le dans le formulaire.
2. Saisissez **0** dans le champ quantité.
3. Appuyez sur **"+ Ajouter"**.

**Ne laissez pas un ingrédient de côté** en pensant qu'il sera automatiquement à zéro. Un ingrédient non saisi lors d'un comptage **conserve la valeur du comptage précédent** dans les calculs — cela peut fausser les coûts de production pendant des semaines sans que personne ne le détecte.

---

### 4.6 En cas de doute sur l'identité d'un ingrédient

Si le libellé d'un sac ou d'un contenant **ne correspond pas clairement** à un ingrédient dans la liste :

1. Essayez différentes graphies : avec/sans accent, abréviation vs nom complet, nom en allemand pour certains malts (ex. "Pilsner" vs "Pils", "Münchner" vs "Munich").
2. Si toujours absent après recherche approfondie → **NE PAS saisir un ingrédient "proche" comme substitut.**
3. **Signalez à votre responsable** : l'ingrédient doit peut-être être créé dans le système. Un manager ou administrateur peut le créer.
4. Revenez saisir le comptage une fois la référence créée.

Une mauvaise attribution dans l'inventaire des matières premières peut fausser les coûts de production — ne jamais approximer.

---

## 5. Bonnes pratiques et erreurs fréquentes

### 5.1 Les bonnes pratiques de l'opérateur de production

#### 1. Saisir au moment, pas après

Chaque formulaire doit être rempli au moment de l'opération ou dans les deux heures qui suivent. Les saisies "de mémoire" en fin de journée introduisent des erreurs : volume approximatif, heure incorrecte, N° de lot oublié, densité recopiée d'une mauvaise ligne.

#### 2. Ne jamais sauter le Cold Crash

Le Cold Crash doit être **coché dans le formulaire Fermentation** pour qu'un lot apparaisse dans la liste de la page Transferts. C'est la première chose à vérifier si un lot "bloqué" n'apparaît pas dans Transferts. Cochez-le dès la mise en froid — ne pas attendre la fin de la journée.

#### 3. Le cast-out ne doit jamais être vide

Le volume cast-out est le chiffre le plus important du brassage (§1.2). Si vous n'avez pas mesuré exactement → estimation honnête + note en commentaire. Jamais laisser vide.

#### 4. Un run = une bière

Dans le conditionnement : ne mélangez jamais deux bières différentes dans la même session. Si vous conditionnez deux bières le même jour, faites deux sessions séparées. Sans exception.

#### 5. Ne jamais saisir le total sur la ligne principale en parallèle

Formats parallèles : chaque ligne parallèle porte **uniquement sa propre quantité**. Saisir le total sur la ligne principale et les détails sur les lignes parallèles = doublement de la production dans le Stock PF.

#### 6. Vide ≠ zéro dans le comptage MP

Ingrédient épuisé → saisir 0 et appuyer "+ Ajouter". Ingrédient non saisi → il conserve la valeur du comptage précédent dans les calculs, ce qui fausse les coûts durablement.

#### 7. Signaler avant de compenser

Si vous constatez un écart entre le stock affiché et la réalité physique → signalez à votre responsable avant d'agir. Ne cherchez pas à "corriger" vous-même en saisissant une valeur fictive ou en compensant. Un problème signalé à temps est résolu en minutes ; un problème contourné peut générer des heures de correction de données.

#### 8. Compter palette par palette (MP)

Les estimations "à la louche" introduisent des erreurs dans les calculs COGS. Sac par sac, palette par palette.

#### 9. Documenter les cas inhabituels

Transfert d'urgence, perte exceptionnelle, disposition inhabituelle, incident technique → ajoutez une note dans le champ commentaire du formulaire et signalez à votre responsable. Une trace écrite dans le système vaut mieux qu'un appel téléphonique oublié.

#### 10. Vérifier la CCT dans le brassage avant de démarrer la fermentation

Si la CCT n'est pas renseignée dans Brassage → le formulaire Fermentation affiche une gate rouge et la soumission est impossible. Toujours renseigner la CCT lors du brassage, avant de passer à la fermentation.

---

### 5.2 Erreurs fréquentes et comment les éviter

| Erreur | Impact | Comment l'éviter |
|---|---|---|
| CCT non renseignée dans le formulaire Brassage | Formulaire Fermentation bloqué (gate rouge) — impossible de saisir des mesures | Toujours renseigner la CCT lors de la saisie du brassage |
| Cold Crash non coché dans Fermentation | Lot invisible dans la liste Transferts — blocage du process | Cocher dès la mise en froid, avant de quitter la cuve |
| Cast-out laissé vide | Calculs de coûts et rendements faussés pour tout le lot | Saisir au moment — estimation + note si non mesuré exactement |
| Saisir le total sur la ligne principale + quantités sur les lignes parallèles | Doublement de la production dans le Stock PF | Chaque ligne parallèle porte uniquement sa propre quantité |
| Mélanger deux bières dans une session de conditionnement | Attribution incorrecte de la production au mauvais lot | Une bière = une session, sans exception |
| Oublier les mesures CO₂/O₂ in-tank avant de commencer | Soumission bloquée par validation à la fin du run | Prendre les mesures avant le premier remplissage |
| Ingrédient MP non saisi (laisser de côté) | Valeur du comptage précédent conservée → calculs COGS faussés durablement | Saisir 0 pour les ingrédients épuisés, ne jamais ignorer |
| Deviner un ingrédient MP non trouvé dans la liste | Mauvaise attribution → erreur dans les coûts de production | Demander au responsable, ne jamais approximer |
| Saisir des pertes standard dans la section Pertes (Transferts) | Doublement des pertes dans les calculs — pertes standard déjà calculées auto | Section Pertes = pertes exceptionnelles uniquement |
| Re-soumettre un run de conditionnement sans vérifier | Doublon de production si le run était déjà enregistré | Vérifier dans le Stock PF avant ; si doute → contacter manager |
| N° de brassin inventé ou recopié d'un lot précédent | Fusion accidentelle de deux lots différents dans le système | Vérifier le N° sur vos notes physiques avant de saisir |
| OG (densité) laissée vide dans le déroulé du brassage | Impossible de suivre la courbe de fermentation correctement | OG = champ le plus important — ne jamais laisser vide |

---

### 5.3 Que faire si quelque chose ne fonctionne pas

| Problème | Première action | Si ça ne se résout pas |
|---|---|---|
| Formulaire Fermentation — bouton grisé (soumission impossible) | Vérifier la gate CCT : rouge = CCT non renseignée dans le formulaire Brassage | Retourner dans Brassage, ajouter la CCT, re-soumettre |
| Lot absent de la liste Transferts | Vérifier que le Cold Crash est coché dans Fermentation | Manager active Hors Process si exception justifiée |
| Lot absent de la liste Conditionnement | Vérifier que le transfert BBT/CCT est bien enregistré (formulaire Transferts) | Responsable vérifie le statut du lot |
| Formulaire ne se soumet pas | Vérifier les champs en rouge (obligatoires manquants) | Contacter votre responsable |
| Ingrédient MP introuvable dans la liste Inventaire MP | Essayer différentes graphies (accent, abréviation, allemand/français) | Responsable crée la référence manquante |
| Page blanche ou erreur d'affichage | Rafraîchir (balayage vers le bas) | Contacter votre responsable |
| Champs CO₂/O₂ in-tank verrouillés ("champs verrouillés") | Lecture déjà saisie aujourd'hui pour ce lot — partagée automatiquement | Manager/Admin peut déverrouiller si correction nécessaire |
| Recette absente de la liste Brassage | La recette n'est pas active dans le système | Admin active ou crée la recette |
| Cage SKU manquant dans Conditionnement | Admin doit créer le SKU cage pour cette bière | Contacter un administrateur |
| Application inaccessible | Vérifier le Wi-Fi ; rafraîchir la page | Tout noter sur papier ; saisir à la reprise avec les dates réelles |
| Avertissement de valeur improbable dans un formulaire | Relire l'avertissement — si valeur correcte, soumettre et signaler au responsable | Si doute sur la valeur, vérifier avant de soumettre |

**Règle générale :** en cas de doute, ne tentez pas de contournement. Un problème signalé à temps est résolu en minutes. Un problème contourné peut générer des heures de correction de données.

---

### 5.4 Scénarios courants et conduite à tenir

Cette section décrit les situations inhabituelles les plus fréquentes en production et la marche à suivre recommandée. Elle complète les procédures standard.

---

#### Scénario 1 : Le lot n'apparaît pas dans la liste Transferts

**Symptôme :** vous cherchez un lot pour un transfert mais il n'apparaît pas dans la liste des lots éligibles.

**Causes possibles et vérifications :**

1. **Le Cold Crash n'est pas enregistré.**
   - Allez dans Fermentation → trouvez le lot → vérifiez si "Cold Crash" est coché.
   - Si non coché : cochez-le maintenant avec la date réelle du Cold Crash et soumettez.
   - Revenez dans Transferts : le lot devrait maintenant apparaître.

2. **La durée de garde minimale n'est pas atteinte.**
   - Le brasseur responsable peut confirmer si le lot est physiquement prêt.
   - Si une exception est justifiée → demandez à un manager d'activer le mode Hors process.

3. **Le brassage n'a pas été saisi.**
   - Si le lot n'existe pas dans le système (aucune trace en Fermentation non plus) → saisissez le brassage en premier.

4. **Le lot est dans une cuve mais la CCT n'est pas renseignée dans le brassage.**
   - Un manager peut corriger l'affectation directement.

**À ne pas faire :** ne pas forcer le transfert en créant une saisie hors procédure. Contactez votre responsable.

---

#### Scénario 2 : Un run de conditionnement a été oublié

**Symptôme :** le Stock PF est plus bas qu'attendu et vous réalisez qu'un run n'a pas été saisi.

**Conduite à tenir :**

1. Retrouvez vos notes ou documents physiques du run (papier de comptage, notes du brasseur, etc.).
2. Ouvrez le formulaire Conditionnement.
3. Saisissez le run avec la **date réelle de la session** (pas la date du jour si c'est différent).
4. Vérifiez que la quantité saisie correspond bien à ce qui a été produit ce jour-là.
5. Soumettez.
6. Consultez le Stock PF pour vérifier que la correction est visible.
7. Signalez à votre responsable qu'un run avait été omis et qu'il a été rattrapé.

> Si vous ne retrouvez pas les données du run (quantité exacte, format, dispositions), prévenez votre responsable plutôt que de saisir une estimation.

---

#### Scénario 3 : Un run a été soumis avec le mauvais lot ou le mauvais format

**Symptôme :** vous avez soumis un run de conditionnement mais vous réalisez ensuite que le lot ou le format sélectionné était erroné.

**Conduite à tenir :**

1. **N'essayez pas de "corriger" en soumettant un deuxième run** avec les bonnes données — cela créerait un doublon dans le Stock PF.
2. Notez immédiatement : la date du run, le lot sélectionné par erreur, le bon lot, le format erroné, le bon format, la quantité.
3. Contactez un administrateur avec ces informations.
4. L'administrateur peut supprimer ou corriger le run erroné dans le système.

---

#### Scénario 4 : Le formulaire de brassage ne retrouve pas la recette dans la liste

**Symptôme :** vous commencez à saisir un brassage pour une recette existante mais elle n'apparaît pas dans la liste déroulante.

**Causes possibles :**

1. La recette est **désactivée** dans le système (saisonnière, retraitée, en refonte).
2. La recette n'a **pas encore été créée** dans le système (nouvelle création).
3. Vous cherchez avec un **nom légèrement différent** (vérifiez l'orthographe exacte).

**Conduite à tenir :**

- Contactez votre responsable ou un administrateur pour activer ou créer la recette.
- En attendant, notez toutes les données du brassage sur papier (ingrédients, volumes, densités, timings) avec les vraies heures — vous pourrez saisir dès que la recette est disponible.

---

#### Scénario 5 : Une mesure de fermentation a été saisie avec une valeur incorrecte

**Symptôme :** vous avez enregistré une densité ou un pH incorrect pour un lot en fermentation.

**Conduite à tenir :**

1. Saisissez immédiatement une **nouvelle mesure corrigée** avec la bonne valeur. Le système enregistre chaque mesure avec son horodatage — la courbe de fermentation affichera les deux valeurs.
2. Notez dans le commentaire de la nouvelle mesure que la mesure précédente était erronée et pourquoi.
3. Signalez à votre responsable l'existence de la valeur erronée pour qu'il puisse la corriger ou la commenter dans le système si nécessaire.

> Pour une correction formelle (suppression de la valeur incorrecte dans le système) → contactez un administrateur.

---

#### Scénario 6 : L'application est inaccessible (panne réseau ou serveur)

**Symptôme :** `app.maltytask.ch` ne s'ouvre plus, ou affiche une erreur de connexion.

**Conduite à tenir :**

1. **Vérifiez d'abord le Wi-Fi** de votre tablette : êtes-vous bien connecté ?
2. Essayez de rafraîchir la page (balayez vers le bas, ou appuyez sur le bouton de rechargement).
3. Essayez depuis un autre appareil pour confirmer que c'est le serveur et non votre tablette.
4. **En attendant le retour de l'application :**
   - Notez tout sur papier : brassages en cours, mesures de fermentation, pertes, volumes de transfert, runs de conditionnement.
   - Notez les dates et heures exactes — c'est ce qui sera saisi à la reprise.
5. Signalez à votre responsable ou à un administrateur système.
6. À la reprise de l'application : saisissez les événements en retard avec leurs **dates et heures réelles**.

---

#### Scénario 7 : Un lot en fermentation a une densité qui ne descend plus

**Symptôme :** vous enregistrez la densité d'un lot depuis plusieurs jours et la valeur ne descend plus (fermentation bloquée ?).

**Conduite à tenir :**

1. Saisissez la mesure normalement dans le formulaire Fermentation — le formulaire n'affiche qu'un avertissement, il ne bloque jamais.
2. Notez l'observation dans le commentaire de la mesure.
3. Signalez à votre responsable ou au brasseur responsable du lot. Ne prenez pas de décision unilatérale sur le lot (re-pitching de levure, transfert anticipé, etc.) sans validation.

---

#### Scénario 8 : Un ingrédient n'est pas trouvable dans la liste de l'Inventaire MP

**Symptôme :** vous cherchez un ingrédient dans le formulaire Inventaire MP mais il n'apparaît pas, même en tapant plusieurs lettres.

**Conduite à tenir :**

1. Essayez différentes graphies : avec/sans accent, abréviation vs nom complet, nom en allemand vs en français pour certains malts (ex. "Pilsner Malt" vs "Malt Pils", "Münchner" vs "Munich").
2. Si vous ne trouvez toujours pas → **ne saisissez pas un ingrédient "proche"** comme substitut. Une mauvaise attribution fausse les calculs de coûts.
3. Signalez à votre responsable : l'ingrédient peut ne pas être encore référencé dans le système.
4. Un manager ou administrateur peut créer la référence manquante.
5. Revenez saisir le comptage une fois la référence créée.

> En cas d'ingrédient manquant, notez la quantité sur papier avec le nom exact tel qu'il est écrit sur le sac / contenant. Cela facilitera la création de la référence par l'administrateur.

---

#### Scénario 9 : Un transfert a été saisi avec la mauvaise cuve de destination

**Symptôme :** vous avez enregistré un transfert vers la BBT 3 alors que c'était la BBT 5.

**Conduite à tenir :**

1. Ne soumettez pas un deuxième transfert "correctif" de votre propre initiative.
2. Contactez un administrateur immédiatement avec les détails : date du transfert, cuve source, cuve destination saisie par erreur, cuve destination réelle, volume transféré.
3. L'administrateur corrige le transfert et vous guide pour la suite.

> Une re-saisie non coordonnée d'un transfert erroné peut créer des doublons ou des incohérences dans les volumes de cuves — toujours passer par un admin.

---

#### Scénario 10 : Deux brassins ont été saisis sous le même numéro de brassin

**Symptôme :** deux brassins distincts (deux recettes différentes, ou même recette mais productions séparées) ont été enregistrés sous le même couple recette + N° de brassin. Le système les a fusionnés.

**Conduite à tenir :**

1. Ne re-soumettez rien de votre propre initiative — cela pourrait écraser des données valides.
2. Notez les données correctes de chacun des deux brassins sur papier.
3. Contactez un administrateur avec les deux ensembles de données.
4. L'administrateur corrige les affectations et vous indique quel N° attribuer au brassin qui doit être renommé.

> Pour éviter cette situation : avant de saisir un brassage, vérifiez dans le tableau des derniers brassins (en bas de la page Brassage) que le numéro que vous allez utiliser n'est pas déjà attribué à un lot récent.

---

## 6. Questions fréquentes

### Sur le brassage

**Q : La recette que je cherche n'est pas dans la liste déroulante. Que faire ?**

La liste ne contient que les recettes **actives**. Si une recette est absente, soit elle n'a pas encore été enregistrée, soit elle est désactivée. Contactez votre responsable ou un administrateur pour l'activer ou la créer.

---

**Q : J'ai oublié de renseigner la CCT lors du brassage. Que faire ?**

Retournez dans le formulaire Brassage → re-soumettez le même couple recette + N° de brassin avec la CCT renseignée → le système met à jour sans créer de doublon (une case de confirmation s'affiche). Vérifiez ensuite que le formulaire Fermentation débloque bien la gate (plus de carré rouge).

---

**Q : Le formulaire m'affiche un avertissement de valeur improbable. Que faire ?**

Lisez l'avertissement attentivement. Si la valeur est correcte (vous avez réellement mesuré cette valeur) → soumettez et signalez à votre responsable. Si vous avez un doute → vérifiez la valeur avant de soumettre. Les avertissements ne bloquent pas la soumission.

---

**Q : Puis-je re-soumettre un brassage pour corriger une erreur ?**

Oui. Re-soumettre le même couple recette + N° met à jour sans créer de doublon. Une case de confirmation s'affiche. Pour les corrections de données clés (volume cast-out, OG) sur un lot déjà en fermentation avancée ou en BBT, préférez passer par un manager ou admin.

---

**Q : Le déroulé du brassage a une ligne avec des données incomplètes. Est-ce un problème ?**

Les lignes entièrement vides sont ignorées. Mais une ligne partiellement remplie peut bloquer la soumission ou fausser les totaux. Remplissez chaque ligne complètement ou supprimez-la si elle est en erreur.

---

### Sur la fermentation

**Q : Le lot n'apparaît pas dans la liste Fermentation. Que faire ?**

Deux causes principales :
1. Le formulaire de brassage n'a pas encore été soumis → aller dans Brassage et le soumettre.
2. La CCT est renseignée mais la fermentation n'a pas encore officiellement "démarré" dans le système → après soumission du brassage, le lot devrait apparaître.

---

**Q : J'ai oublié de cocher le Cold Crash hier. Puis-je le faire aujourd'hui ?**

Oui. Retournez dans Fermentation → sélectionnez le lot → choisissez "Mesures densité / pH / temp" → cochez la case Cold Crash et entrez la **date réelle** du cold crash (pas la date du jour si c'est différent). Soumettez. Signalez à votre responsable si un transfert était en attente — le lot devrait maintenant apparaître dans Transferts.

---

**Q : J'ai un lot qui appartient à un autre opérateur dans la liste Fermentation. Puis-je le modifier ?**

Non. Ne modifiez jamais un lot d'un autre opérateur. Si vous pensez qu'il y a une erreur sur ce lot, signalez-le à votre responsable.

---

**Q : Je vois un lot dont le Cold Crash est coché mais il n'apparaît toujours pas dans Transferts.**

Vérifiez que la durée de garde minimale est atteinte depuis la date du Cold Crash. Si oui et que le lot n'apparaît toujours pas → contactez votre responsable. Si non → attendez l'échéance ou demandez au manager d'activer Hors Process si une exception est justifiée.

---

**Q : Combien de temps faut-il attendre entre le Cold Crash et le transfert ?**

La durée de garde minimale dépend de la souche de levure. Le formulaire Fermentation affiche cette information à titre indicatif (Gate 2 — informatif seulement). Votre responsable vous communique les durées par souche.

---

### Sur les transferts

**Q : Un lot est prêt physiquement mais absent de la liste Transferts. Que faire ?**

Vérifications dans l'ordre :
1. Cold Crash coché dans Fermentation ? → si non : cocher avec la date réelle.
2. Garde minimale atteinte ? → si non : attendre, ou manager active Hors Process si exception justifiée.
3. Brassage soumis avec CCT renseignée ? → si non : corriger dans Brassage.
4. Si tout est correct et le lot n'apparaît toujours pas → contacter le responsable.

---

**Q : La cuve de destination contenait déjà de la bière d'un autre lot. Que faire ?**

Saisissez le volume résiduel dans le champ "Volume résiduel en cuve". Le système calcule automatiquement le volume résultant. Si la bière résiduelle est de la **même recette**, une section de blending apparaît. Si elle est d'une **autre recette** → ne pas faire ce transfert sans accord du responsable.

---

**Q : J'ai commis une erreur sur un transfert déjà soumis. Que faire ?**

Contactez un administrateur immédiatement avec les détails : date du transfert, cuves concernées, volumes. Ne re-soumettez pas un transfert "correctif" de votre propre initiative.

---

**Q : Je dois faire un transfert KZE (pasteurisation). La section pasteurisation n'apparaît pas. Pourquoi ?**

La section pasteurisation (champs PU) est masquée jusqu'à ce que **KZE soit coché dans la section CIP**. Cochez KZE dans les machines, et la section apparaîtra.

---

### Sur le conditionnement

**Q : J'ai oublié de prendre les mesures CO₂/O₂ avant de commencer. Que faire ?**

Prenez-les dès que possible, le plus tôt possible pendant le run. Indiquez en commentaire que les mesures n'étaient pas strictement in-tank avant démarrage. Le formulaire requiert ces valeurs avant de laisser soumettre — vous ne pourrez pas finir la saisie sans elles.

---

**Q : Les champs CO₂/O₂ sont verrouillés ("champs verrouillés"). Pourquoi ?**

Une lecture in-tank a déjà été saisie aujourd'hui pour ce lot. Elle est partagée automatiquement entre tous les formats de la même journée — vous n'avez pas besoin de les re-saisir. Si une correction est nécessaire → un manager ou admin peut déverrouiller les champs.

---

**Q : J'ai oublié de saisir un run de conditionnement d'hier. Puis-je le saisir aujourd'hui ?**

Oui. Les formulaires acceptent une date passée pour la session. Saisissez-le avec la **date réelle** de la session. Signalez à votre responsable l'écart de stock temporaire qui peut avoir existé entre-temps.

---

**Q : Le formulaire m'indique une erreur de cage SKU manquant. Que faire ?**

Le SKU cage (format X) n'existe pas encore pour cette bière dans le système. Contactez un administrateur pour qu'il le crée. En attendant, notez le nombre de bouteilles sur papier.

---

**Q : J'ai conditionné en white label mais le client n'est pas dans la liste. Que faire ?**

Contactez votre responsable ou un administrateur pour ajouter le client. En attendant, notez toutes les informations du run sur papier pour saisie ultérieure.

---

**Q : Comment faire si j'ai deux formats différents du même lot le même jour ?**

Sélectionnez le lot, ajoutez le premier format (format principal), puis appuyez sur "+ Ajouter un format parallèle" pour le second. Chaque ligne porte **uniquement sa propre quantité** — ne saisissez pas la quantité totale sur la ligne principale.

---

**Q : J'ai conditionné avec le mauvais lot sélectionné. Que faire ?**

Contactez un administrateur immédiatement. Ne resoumettez rien de votre propre initiative — deux soumissions erronées créent plus de confusion qu'une seule.

---

### Sur le comptage MP

**Q : Un ingrédient n'est pas trouvable dans la liste Inventaire MP. Que faire ?**

1. Essayez différentes graphies : avec/sans accent, abréviation, nom en allemand pour certains malts.
2. Si toujours absent → **NE PAS saisir un ingrédient proche comme substitut**.
3. Signalez au responsable — l'ingrédient doit être créé dans le système.
4. Revenez saisir le comptage une fois la référence créée.

---

**Q : J'ai saisi une quantité par erreur dans l'Inventaire MP. Puis-je la corriger ?**

Oui. Dans le registre "Saisies du mois", chaque ligne a un bouton **"✕"** pour la supprimer. Supprimez la ligne erronée, puis saisissez la bonne quantité en appuyant sur "+ Ajouter".

---

**Q : Je dois compter un mois différent du mois en cours. Comment faire ?**

Modifiez la période en haut du formulaire si vous êtes habilité à le faire. Si vous ne voyez pas l'option ou si elle est bloquée → demandez à votre responsable.

---

**Q : Est-ce que je peux faire le comptage MP en plusieurs fois, sur plusieurs jours ?**

Oui. Chaque ligne est sauvegardée immédiatement quand vous appuyez sur "+ Ajouter". Vous pouvez quitter et revenir autant de fois que nécessaire. Le registre "Saisies du mois" montre tout ce qui a déjà été saisi pour la période.

---

**Q : Dois-je compter tous les ingrédients d'un coup, ou puis-je me concentrer sur une zone à la fois ?**

Vous pouvez compter dans n'importe quel ordre et par zones (malts d'abord, houblons ensuite, etc.). L'important est de couvrir **tous les ingrédients** avant la fin du mois.

---

## Annexe A — Glossaire production

Ce glossaire définit les termes utilisés dans l'application et dans ce manuel. Seuls les termes opérateurs sont listés ici — aucun jargon technique ou informatique.

| Terme | Définition |
|---|---|
| **BBT** | Bright Beer Tank : cuve de bière transférée depuis la CCT, clarifiée / filtrée, prête à conditionner. La bière y est gardée jusqu'à mise en emballage. |
| **CCT** | Cuve de Fermentation cylindro-conique (Cylindro-Conical Tank). La bière fermente et mûrit ici avant son transfert en BBT. |
| **YT** | Cuve à levure (Yeast Tank) : cuve de stockage de la levure récoltée après fermentation, en vue de la réutiliser lors du brassin suivant. |
| **Cold Crash** | Refroidissement forcé d'un lot en fin de fermentation. Coché dans le formulaire Fermentation — déclenche l'éligibilité au transfert en BBT. |
| **Cast-out** | Volume de moût transféré en cuve de fermentation (CCT) après refroidissement, à la fin d'un brassin. Le chiffre le plus important du brassage. |
| **°Plato** | Degrés Plato : mesure de la concentration en sucre du moût ou de la bière. Plus la valeur est élevée, plus le liquide est concentré en sucre. |
| **OG (Original Gravity)** | Densité initiale à l'entrée en fermentation (= densité mesurée au cast-out / refroidissement). Point de départ du suivi de fermentation. |
| **FG (Final Gravity)** | Densité finale en fin de fermentation. Quand la FG est stable et proche de la valeur cible, la fermentation est terminée. |
| **Garde** | Période de maturation d'un lot après fermentation. Chaque souche de levure a une durée de garde minimale recommandée. |
| **Brassin** | Un cycle de brassage (remplissage → filtration → ébullition → refroidissement → CCT). Un lot peut comprendre 1 à plusieurs brassins successifs selon la taille de la CCT. |
| **Lot** | Ensemble des brassins identifiés par la combinaison recette + N° de brassin. Tracé de A (brassage) à Z (conditionnement). |
| **Centrifuge** | Machine de clarification utilisée lors du transfert CCT → BBT. Sépare les levures et particules en suspension de la bière. |
| **KZE** | Filtre de pasteurisation flash utilisé lors de certains transferts (Kerzenfilter-Zentrifuge). Mesure en PU (Pasteurisation Units). |
| **PU** | Unités de pasteurisation (Pasteurisation Units) : mesure de l'efficacité du traitement thermique KZE. |
| **Pompe** | Transfert direct de cuve à cuve par pompe, sans centrifuge ni KZE. |
| **Blending** | Mélange du résidu d'un lot précédent dans une BBT avec un nouveau lot entrant. Toujours de la même recette — jamais deux bières différentes. |
| **Houblonnage à froid** | Ajout de houblon directement dans la cuve froide après fermentation pour apporter des arômes sans amertume. Terme technique : dry-hopping. |
| **Purge** | Soutirage des levures mortes et sédiments accumulés au fond de la CCT pendant la fermentation. |
| **Disposition** | En conditionnement : catégorie d'unités sorties du circuit vendable (casse, QA, taproom, invendable…). Voir Annexe B. |
| **Run** | Une session de conditionnement (mise en bouteille, canette, fût ou cuve de service). |
| **Cuve de service** | Grand contenant de bière pression pour le taproom ou un festival (terme technique : "cuv"). |
| **Fût 20L** | Fût de 20 litres de bière pression (terme anglais : keg). |
| **White label** | Production conditionnée sous le label d'un client tiers (marque blanche). |
| **Hors process** | Mode réservé aux managers/admins : déverrouille des opérations normalement bloquées par les gardes ou délais minimaux. |
| **Soutirage** | Transfert de bière depuis une CCT ou BBT vers une autre cuve, ou vers la ligne de conditionnement. Même terme que "transfert" dans ce contexte. |
| **Soutireuse** | Machine de remplissage (filler) utilisée lors du conditionnement pour remplir les bouteilles, canettes ou fûts. |
| **Taproom keg** | Fût branché directement au service du taproom depuis la salle de cuves, sans passer par le stock vendable. |
| **Matières premières (MP)** | Ingrédients et consommables de production : malts, houblons, adjuvants, minéraux, emballages, produits de nettoyage. |
| **CIP** | Nettoyage en place (Cleaning In Place) des cuves et équipements. Effectué avant chaque utilisation d'une cuve ou machine. |
| **DLC / BBD** | Date Limite de Consommation / Best Before Date. Mois de péremption indiqué sur le produit fini. |
| **Première trempe** | Premier jus de filtration issu du brassin filtré, avant remplissage complet de la chaudière. |
| **Pfannevoll** | Densité mesurée au remplissage complet de la chaudière, avant ébullition. |
| **Kochwürze** | Densité mesurée en fin d'ébullition, avant refroidissement. |
| **SKU** | Référence d'un produit fini (ex. ZEPF = Zepp en Fût 20L). Chaque format d'une bière a son propre SKU. Voir Annexe C. |
| **Stock PF** | Stock de Produits Finis : bouteilles, canettes, fûts, cuves de service prêts à vendre ou livrer. |

---

## Annexe B — Dispositions de conditionnement

Les dispositions permettent d'enregistrer avec précision ce qui sort du circuit vendable lors d'un run de conditionnement. Choisir la bonne disposition est important pour la traçabilité et pour le calcul de la taxe bière.

### Tableau complet des dispositions

| Disposition | Ce que ça signifie | Taxée ? | Comptée en stock ? |
|---|---|---|---|
| **Invendable** | Bière produite mais non vendable (goût, aspect, défaut). Consommable mais pas vendable. | Oui | Non (hors stock vendable) |
| **Unité perdue (pleine)** | Unité pleine définitivement perdue (casse avant capsulage, vol constaté, etc.). BOM entier déduit. | Non | Non |
| **Perte liquide sans capsule** | Bière remplie dans le contenant mais jamais capsulée / fermée. | Non | Non |
| **Perte liquide à moitié remplie** | Contenant à moitié rempli — compte pour 0,5 unité dans le BOM. | Non | Non |
| **Bibliothèque QA** | Échantillons conservés pour la bibliothèque qualité (archivage, analyses futures). | Neutre | Non |
| **Mesures QA** | Unités utilisées pour les analyses qualité en cours (pH, oxygène, micro…). | Neutre | Non |
| **Fût taproom** | Fût mis directement en service au taproom sans passer par le stock vendable. | Oui | Non (hors stock vendable) |
| **Perte capuchon fût** | Perte de capuchon de fût uniquement — ne touche pas au volume. | N/A | N/A |
| **Perte étiquettes** | Perte d'étiquettes uniquement — ne touche pas au volume. | N/A | N/A |
| **Perte 4-packs** | Perte d'emballages 4-packs uniquement — ne touche pas au volume. | N/A | N/A |

### Comment les utiliser

- **Volume vendable** : calculé automatiquement par le système à partir des dispositions. Ne le saisissez pas.
- Les dispositions **"Perte capuchon / étiquettes / 4-packs"** n'affectent que les matières premières d'emballage, pas la bière elle-même.
- En cas de doute sur quelle disposition utiliser → utilisez **"Invendable"** et précisez en note. Un responsable peut corriger si nécessaire.
- N'inventez pas de dispositions. Utilisez uniquement celles de cette liste.

### Exemples concrets

| Situation | Disposition à choisir |
|---|---|
| Caisse tombée, bouteilles cassées | Unité perdue (pleine) |
| Bouteilles remplies avec mousse excessive, bouchées sans carbonatation correcte | Invendable |
| Bouteilles remplies mais la capsuleuse tombe en panne avant capsulage | Perte liquide sans capsule |
| Bouteilles remplies à mi-course d'un run interrompu | Perte liquide à moitié remplie |
| 2 bouteilles mises de côté pour analyse au labo | Mesures QA |
| 6 bouteilles archivées pour la bibliothèque du brasseur | Bibliothèque QA |
| Fût branché directement au taproom depuis la salle de cuves | Fût taproom |
| 10 capuchons de fûts perdus pendant la pose | Perte capuchon fût |

---

## Annexe C — Références SKU : suffixes de format

Les références SKU sont les codes compacts utilisés dans toute l'application pour identifier chaque produit fini. Ils se lisent comme : **code bière** + **suffixe format**.

### Suffixes de format

| Suffixe | Format complet | Contenance |
|---|---|---|
| **F** | Fût 20L | 20 litres |
| **V** | Cuve de service | Variable |
| **4** | Carton 6×4 | 24 bouteilles 33cl |
| **B** | Box 24 | 24 canettes |
| **C** | Canette unitaire | 50cl ou 33cl |
| **BU** | Bouteille unitaire | 33cl |
| **CU** | Canette unitaire (format alternatif) | 33cl ou 50cl |
| **X** | Cage / vrac bouteilles | Variable |

### Exemples de lecture

| Code SKU | Se lit comme |
|---|---|
| `ZEPF` | Zepp en Fût 20L |
| `ZEP4` | Zepp en carton 6×4 (24 bouteilles) |
| `ZEPC` | Zepp en canette unitaire |
| `EMB4` | Embuscade en carton 6×4 |
| `EMBF` | Embuscade en Fût 20L |
| `MOONF` | Moonshine en Fût 20L |
| `STIF` | Stirling en Fût 20L |
| `DIVF` | Diversion en Fût 20L |

> Les codes bière (ZEP, EMB, MOON, STI, DIV, etc.) sont stables et ne changent pas. Seuls les suffixes varient selon le format.

---

## Annexe D — Listes de contrôle (checklists)

Ces checklists sont des aide-mémoire rapides. Elles ne remplacent pas les procédures détaillées du manuel.

---

### Checklist quotidienne — Opérateur de production

#### Matin

- [ ] Vérifier la liste Fermentation : y a-t-il des relevés de mesures à saisir aujourd'hui ?
- [ ] Vérifier les lots en CCT : des Cold Crash sont-ils attendus aujourd'hui ?
- [ ] Vérifier si un transfert ou un run de conditionnement est prévu pour aujourd'hui.
- [ ] Confirmer avec le brasseur responsable les lots en cours et les opérations du jour.

#### En cours de journée

- [ ] Saisir les brassages dès la fin de chaque cycle (dans les 2h).
- [ ] Saisir les relevés de fermentation au moment du relevé.
- [ ] Cocher le Cold Crash dès la mise en froid (ne pas oublier en fin de journée).
- [ ] Saisir les transferts dès la fin de l'opération.
- [ ] Prendre les mesures CO₂/O₂ in-tank avant tout run de conditionnement.
- [ ] Saisir les runs de conditionnement dans les 2h suivant la fin de la session.
- [ ] En cas de perte exceptionnelle : renseigner la section Pertes dans le formulaire Transferts ou noter pour le formulaire Conditionnement.

#### Soir

- [ ] Vérifier que tous les brassages de la journée ont été saisis.
- [ ] Vérifier que toutes les mesures de fermentation de la journée ont été enregistrées.
- [ ] Vérifier que les Cold Crash sont cochés pour tous les lots refroidis aujourd'hui.
- [ ] Vérifier que tous les transferts de la journée ont été saisis.
- [ ] Vérifier que tous les runs de conditionnement de la journée ont été saisis.

---

### Checklist — Saisie d'un run de conditionnement

À compléter pour chaque session de mise en emballage.

**Avant de commencer la session physique :**
- [ ] Confirmer le lot source (bière en BBT/CCT) avec le brasseur responsable.
- [ ] Prendre les mesures CO₂ et O₂ in-tank avant le premier remplissage.
- [ ] Identifier le(s) format(s) à conditionner ce jour.
- [ ] Si white label : confirmer le nom du client avec le responsable.

**Pendant la session :**
- [ ] Compter les unités au fur et à mesure (ne pas se fier à la mémoire en fin de run).
- [ ] Identifier et compter les dispositions (casse, QA, invendables…) au fil de la session.
- [ ] Prendre les relevés CO₂/O₂ in-filling aux intervalles prévus.

**Saisie dans l'application :**
- [ ] Ouvrir Conditionnement.
- [ ] Sélectionner le bon lot source.
- [ ] Sélectionner le bon type de run.
- [ ] Sélectionner le bon format (et le bon suffixe).
- [ ] Saisir CO₂ et O₂ in-tank (si pas déjà repris automatiquement).
- [ ] Saisir la quantité principale.
- [ ] Si formats parallèles : ajouter chaque ligne parallèle avec sa propre quantité.
- [ ] Compléter les dispositions si nécessaire.
- [ ] Relire le récapitulatif avant soumission : bière, format, quantité.
- [ ] Soumettre.
- [ ] Vérifier que le run apparaît correctement dans le Stock PF (vue Stock PF par site).

---

### Checklist mensuelle — Comptage Matières Premières

À réaliser en fin de mois, idéalement sur 1 à 2 jours.

**Avant de commencer :**
- [ ] Vérifier que le comptage du mois précédent est archivé (votre responsable peut le confirmer).
- [ ] Avoir accès à toutes les zones de stockage MP (magasin malts, chambre froide houblons, local emballages, local produits de nettoyage…).
- [ ] Identifier les ingrédients récemment réceptionnés qui ne seraient pas encore dans la liste (signaler au responsable).

**Pendant le comptage :**
- [ ] Malts : compter sac par sac, noter le poids (kg) et le N° de lot si visible.
- [ ] Houblons : compter par contenant/variété, noter en kg ou g.
- [ ] Adjuvants et minéraux : noter en kg, g ou L selon l'unité habituelle.
- [ ] Emballages : noter en nombre d'unités (capsules, étiquettes, cartons) ou en poids si applicable.
- [ ] Produits de nettoyage : noter en L ou kg.

**Saisie dans l'application :**
- [ ] Ouvrir Inventaire MP.
- [ ] Vérifier que la période est bien le mois en cours.
- [ ] Pour chaque palette / conteneur compté : chercher l'ingrédient → saisir la quantité → "+ Ajouter".
- [ ] Pour les ingrédients épuisés : chercher → saisir 0 → "+ Ajouter".
- [ ] Fermer l'application une fois terminé (la sauvegarde est automatique ligne par ligne).

**Après saisie :**
- [ ] Signaler à votre responsable si un ingrédient attendu n'est pas dans la liste.
- [ ] Signaler tout stock négatif ou suspect (ex. stock présent mais non consommé depuis plusieurs mois).

---

### Conseils pour l'utilisation sur tablette

Pour une utilisation optimale de l'application sur tablette en atelier :

- **Orientation :** paysage (horizontale) pour les tableaux larges (Déroulé du brassage, registre MP), portrait (verticale) pour les formulaires de saisie rapide.
- **Luminosité :** au maximum en atelier ou en extérieur pour une lisibilité optimale.
- **Clavier virtuel :** peut masquer une partie du formulaire. Faire défiler vers le haut si un champ disparaît derrière le clavier.
- **Ne pas taper plusieurs fois sur Soumettre** si la page tarde à répondre — une seule pression suffit. Si la page semble bloquée, attendez 10 à 15 secondes avant de rafraîchir.
- **Session active :** sur tablette partagée, déconnectez-vous à la fin de votre poste. Ne partagez jamais votre mot de passe.
- **En cas de blocage de l'écran pendant une saisie :** retourner dans le formulaire — certains formulaires conservent la saisie en cours. Si les données sont perdues → ressaisir depuis les notes papier.
- **Conseil préventif :** pour les longues saisies (brassage multi-sous-brassin, comptage MP complet), notez les données sur papier en parallèle avant de saisir dans l'application. Cela vous protège en cas de coupure Wi-Fi ou de batterie vide.
- **Accès rapide :** ajoutez `app.maltytask.ch` à votre écran d'accueil de tablette pour l'ouvrir en un tap (voir §2.1).

---

*Manuel opérateur — Production & Brassage — La Nébuleuse — juin 2026.*
*Pour toute question ou suggestion d'amélioration, contacter votre responsable.*
*Ce document est interne à La Nébuleuse. Ne pas diffuser en dehors de l'entreprise.*
