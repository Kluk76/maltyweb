# Manuel opérateur — Logistique & Entrepôt
### app.maltytask.ch — La Nébuleuse

> **Version** : juin 2026
> **Public** : Opérateurs logistique et entrepôt
> **Support** : Contacter votre responsable ou un administrateur système

---

## À qui s'adresse ce manuel

Ce manuel est écrit pour les opérateurs logistique et entrepôt de La Nébuleuse. Il suppose que vous :

- avez un compte actif sur **app.maltytask.ch**
- avez effectué (ou allez effectuer) la **Visite guidée** au premier lancement
- travaillez sur **tablette** en atelier ou en entrepôt

Il couvre tout ce dont vous avez besoin au quotidien : suivre les étapes de production, compter le stock, préparer et expédier des commandes, gérer les retours.

**Ce manuel ne demande aucune connaissance informatique.** Si quelque chose ne fonctionne pas comme décrit, prévenez votre responsable — ne tentez pas de contourner.

Ce manuel est le compagnon long-format de la Visite guidée. La Visite guidée vous montre où aller ; ce manuel vous explique comment faire, pourquoi, et quoi éviter.

---

## Table des matières

1. [Introduction — Comment fonctionne le Stock PF](#1-introduction--comment-fonctionne-le-stock-pf)
   - 1.1 Principe : stock calculé, jamais saisi à la main
   - 1.2 La formule en clair
   - 1.3 Pourquoi le comptage est capital
   - 1.4 Semaines de couverture
2. [Accès à l'application — Premiers repères](#2-accès-à-lapplication--premiers-repères)
   - 2.1 Se connecter
   - 2.2 La barre de navigation
   - 2.3 La Visite guidée
   - 2.4 Expéditions : le hub logistique
3. [Le quotidien](#3-le-quotidien)
   - 3.1 Saisir les étapes de production
   - 3.2 Réceptionner une livraison
   - 3.3 Préparer et expédier les commandes
4. [L'hebdomadaire](#4-lhebdomadaire)
   - 4.1 Comptage Stock PF
   - 4.2 Comptage Matières Premières
5. [Au besoin](#5-au-besoin)
   - 5.1 Retours et avoirs
   - 5.2 Déballage / Assemblage
   - 5.3 Transferts inter-sites
   - 5.4 Stock d'accompagnement
6. [Lire le tableau de stock](#6-lire-le-tableau-de-stock)
7. [Bonnes pratiques et erreurs fréquentes](#7-bonnes-pratiques-et-erreurs-fréquentes)
8. [Questions fréquentes](#8-questions-fréquentes)
- [Annexe A — Récapitulatif des statuts de commande](#annexe-a--récapitulatif-des-statuts-de-commande)
- [Annexe B — Accès rapide aux vues Expéditions](#annexe-b--accès-rapide-aux-vues-expéditions)
- [Annexe C — Références SKU : suffixes de format](#annexe-c--références-sku--suffixes-de-format)
- [Annexe D — Glossaire opérateur](#annexe-d--glossaire-opérateur)
- [Annexe E — Dispositions de conditionnement](#annexe-e--dispositions-de-conditionnement)

---

## 1. Introduction — Comment fonctionne le Stock PF

### 1.1 Principe : un stock calculé, jamais saisi à la main

Le **Stock PF** (produits finis) affiché dans l'application n'est **pas** un nombre que quelqu'un a tapé manuellement. C'est un **calcul automatique en temps réel**, effectué à partir de plusieurs sources d'information.

Cette approche a un avantage majeur : le stock est toujours à jour, sans qu'un opérateur ait besoin de mettre à jour une feuille de calcul après chaque mouvement.

Elle a aussi une conséquence directe sur votre travail : **si une saisie est manquante ou incorrecte, le stock affiché sera faux**. L'application est aussi fiable que les données qu'elle reçoit. En d'autres termes :

- Un run de conditionnement non saisi → la production n'est pas comptée.
- Un transfert inter-sites non enregistré → le stock du site d'arrivée reste faux.
- Un comptage en retard → les petits écarts s'accumulent sans être corrigés.

Votre travail de saisie n'est pas une formalité administrative. C'est ce qui fait fonctionner le système.

### 1.2 La formule en clair

Pour chaque référence (SKU) et chaque site, l'application calcule :

```
Stock physique =
    Dernier comptage validé (pour ce site)
    + Production depuis ce comptage    (runs de conditionnement par site)
    − Expéditions B2B livrées         (commandes livrées, date > date du dernier comptage)
    − Ventes boutique en ligne        (commandes e-shop, date > date du dernier comptage)
    − Ventes taproom                  (grand livre, période > mois du dernier comptage)
```

Le **dernier comptage** est le point d'ancrage. Tout ce qui se passe après ce comptage est calculé automatiquement. C'est pourquoi compter souvent et précisément est si important.

#### La règle des cases vides vs zéro

Lors d'un comptage, chaque case du formulaire peut être dans trois états :

| État de la case | Interprétation par le système |
|---|---|
| **Case vide** | ⚠️ Traité comme **0** — la référence est considérée absente de ce comptage |
| **Case à 0** | Stock à zéro — **identique** à une case vide |
| **Case avec un chiffre** | Quantité physique réelle à cet instant |

> **Règle absolue — un comptage est un recensement COMPLET.** Toute référence que vous ne saisissez pas passe automatiquement à **0** pour ce site : il n'y a **aucun report** de l'ancien comptage. « Case vide » et « case à 0 » donnent donc le **même** résultat. Vous devez compter **chaque référence présente** à chaque comptage — sinon les références oubliées tombent à zéro sur le tableau. La valeur « précéd. » affichée à côté de la case n'est qu'un **repère visuel** : elle n'est ni conservée ni réutilisée par le système.

#### Un comptage par site, pas un comptage global

Chaque site a son propre dernier comptage. Si vous comptez Le Zeppelin mais pas l'entrepôt de Zgeg le même jour, les deux sites utilisent des dates d'ancrage différentes. C'est normal. Ce qui compte, c'est que chaque site soit compté régulièrement.

### 1.3 Pourquoi le comptage est capital

Un comptage régulier et précis est le seul mécanisme de correction disponible pour les écarts que la saisie ne capture pas : casse accidentelle, erreurs d'étiquetage, pertes non déclarées, bière offerte hors procédure.

Sans comptage régulier, ces écarts s'accumulent de semaine en semaine. Au bout d'un mois, le stock affiché peut différer du stock réel de façon significative.

**Fréquence recommandée :** au moins une fois par semaine par site actif, de préférence en fin de semaine avant de partir. Le lundi matin est acceptable si la semaine a été calme.

Le comptage est aussi ce qui permet à la direction et aux responsables de commandes de faire confiance aux chiffres. Un stock fiable = des décisions de production fiables = moins de ruptures ou de surstock.

### 1.4 Semaines de couverture

L'application calcule automatiquement les **semaines de stock** pour chaque référence. C'est une estimation du temps avant rupture, basée sur la vitesse de vente récente et les prévisions saisonnières.

| Indicateur | Seuil | Ce que ça veut dire |
|---|---|---|
| **Bien fourni** | 10 semaines ou plus | Pas d'action immédiate |
| **Bas** | Moins de 3 semaines | À surveiller, peut nécessiter un brassage |
| **Critique** | Moins de 1 semaine | Action urgente : brassage, arbitrage commandes |
| **Survendu** | Commandes ouvertes > stock disponible | Signal "produire" — pas une erreur système |

**Survendu ne veut pas dire que le système est cassé.** Cela veut dire qu'il y a plus de commandes que de stock disponible. C'est un signal d'alerte pour la production, pas un problème à "corriger" dans le système.

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

| Section | Ce que vous y trouvez |
|---|---|
| **Expéditions** | Commandes, stock PF, comptage, transferts, retours — tout le logistique |
| **Brassage** | Saisie des informations d'un brassin |
| **Fermentation** | Suivi des fermentations en cuve |
| **Transferts** | Saisie des transferts de cuve à cuve |
| **Conditionnement** | Saisie des runs de mise en bouteille, canette, fût |
| **Approvisionnement** | Consultation des fournisseurs et livraisons de matières premières |
| **Inventaire MP** | Comptage des stocks de matières premières |

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

### 2.4 Expéditions : le hub logistique

La section **Expéditions** est votre point d'entrée principal pour tout ce qui concerne le stock de produits finis, les commandes et les livraisons.

Elle regroupe plusieurs **vues** accessibles via un menu interne (onglets ou liens en haut de la page). Chaque vue a une fonction précise.

#### Vue d'ensemble des vues Expéditions

| Vue | Utilité principale | Fréquence d'utilisation |
|---|---|---|
| **Commandes** | Suivi et mise à jour des commandes B2B | Quotidien |
| **Boutique en ligne** | Commandes e-shop (lecture + suivi statuts) | Quotidien |
| **Saisie commande** | Créer une commande interne | Selon besoin |
| **Stock PF par site** | Tableau de stock physique par référence et par site | Quotidien |
| **Comptage Stock PF** | Saisie du recensement hebdomadaire | Hebdomadaire |
| **Clients** | Liste clients (modification : managers/admins) | Selon besoin |
| **Transferts inter-sites** | Journal des mouvements entre sites | Selon besoin |
| **Déballage / Assemblage** | Propositions de déballage pour e-shop | Selon besoin |
| **Historique** | Commandes livrées sur une plage de dates | Selon besoin |
| **Retours & avoirs** | Saisie des retours physiques | Selon besoin |
| **Stock d'accompagnement** | Emballages mis de côté, giveaways | Selon besoin |

---

## 3. Le quotidien

### 3.1 Saisir les étapes de production

Chaque étape de production doit être saisie dans l'application dès qu'elle est réalisée. Ces saisies alimentent :

- Le calcul du **Stock PF** (les runs de conditionnement).
- La **traçabilité des lots** (qui est dans quelle cuve, à quelle densité, à quelle date).
- Les **alertes de suivi** (lot bloqué, dérive de densité).

**Règle générale :** saisir au moment où ça se passe, pas en fin de journée de mémoire. Une heure ou deux de décalage sont acceptables. Une journée de retard commence à introduire des erreurs.

Les formulaires sont conçus pour être remplis en atelier, sur tablette. Ne cherchez pas à tout mémoriser — ayez ce manuel sous la main.

---

#### Brassage

**Où :** Menu → **Brassage**

Le formulaire de brassage enregistre toutes les informations d'un brassin. Il peut être soumis plusieurs fois pour le même lot sans créer de doublon : le système met à jour les informations existantes.

##### Identité du lot

Le couple **recette + N° de brassin** est l'identité unique et permanente du lot. Tout ce qui suit (fermentation, transfert, conditionnement) se rattachera à ce couple.

- La recette se choisit dans une liste déroulante (les recettes actives sont listées).
- Le N° de brassin est un numéro séquentiel que vous attribuez pour cette recette (ex. : Zepp n°12 = le douzième brassin de Zepp de la saison).
- Si vous resoumettez le même couple recette + N° de brassin, le système **met à jour** sans créer de doublon.

##### Étapes de saisie

1. **Ouvrez** la page Brassage depuis le menu.
2. **Choisissez la recette** dans la liste déroulante.
3. **Saisissez le N° de brassin.**
4. Remplissez les sections dans l'ordre ci-dessous.

##### Section Ingrédients

Saisissez une ligne par matière première utilisée : malts, houblons, adjuvants, minéraux, agents de process.

| Champ | Ce qu'on saisit | Notes |
|---|---|---|
| **Ingrédient** | Choisir dans la liste ou recherche par type-ahead | |
| **Quantité** | Poids ou volume utilisé | |
| **Unité** | kg, g ou ml | Vérifier l'unité avant de saisir |
| **N° de lot** | Numéro de lot du sac / conteneur | Illisible ou absent → laisser vide |

> N° de lot illisible sur un sac ? Laissez la case vide plutôt que d'inventer un numéro. Un N° de lot inventé est pire qu'une case vide pour la traçabilité.

##### Section Levure

| Champ | Ce qu'on saisit |
|---|---|
| **Souche** | Référence de la souche de levure |
| **Génération** | Numéro de génération (1 = levure fraîche) |
| **Provenance** | Maison, achat extérieur, autre brasserie |

##### Section Brassins (sub-brews)

Si le brassin est réalisé en plusieurs sous-brassins (cuves multiples), saisissez une ligne par brassin.

| Champ | Ce qu'on saisit |
|---|---|
| **Début** | Date et heure de début du brassin |
| **Fin** | Date et heure de fin du brassin |

##### Section Densités °Plato

Les densités sont des mesures de concentration en sucre du moût à différentes étapes.

| Mesure | Quand la prendre |
|---|---|
| **FirstWort** | Premier jus de filtration |
| **Pfannevoll** | Remplissage de la chaudière |
| **Kochwürze** | En fin d'ébullition |
| **Cooling** | Au refroidissement (= densité initiale, OG) |

> La densité **Cooling** est la plus importante : c'est la densité initiale de fermentation. Ne la laissez pas vide.

##### Section Volume à froid

Le volume à froid (cast-out) est le volume de moût transféré en cuve de fermentation après refroidissement. C'est le **chiffre le plus important du formulaire** : il conditionne tous les calculs en aval (rendements, coûts de production, stock potentiel).

| Champ | Ce qu'on saisit |
|---|---|
| **Volume par brassin** | Volume en hectolitres (HL) pour chaque sous-brassin |
| **Dilution** | Si dilution à l'eau froide, saisir le volume ajouté |

> Ne laissez jamais cette case vide. Si vous n'avez pas mesuré exactement, donnez une estimation et précisez en note.

##### Section CIP (nettoyages)

Cochez les nettoyages effectués et saisissez la date/heure pour chacun.

##### Soumission

Appuyez sur **Soumettre** en bas du formulaire. Un message de confirmation s'affiche. Si le formulaire ne se soumet pas, vérifiez les champs obligatoires signalés en rouge.

##### Garde-fous et avertissements

L'application vérifie certaines valeurs à la soumission et peut afficher des **avertissements** (en jaune ou orange) si une valeur semble hors plage normale (ex. densité très basse, volume très élevé). Ces avertissements **ne bloquent pas** la soumission — c'est à vous de vérifier si la valeur est correcte ou s'il s'agit d'une erreur de saisie.

Si vous voyez un avertissement et que vous êtes sûr de la valeur → soumettez quand même et notez-le à votre responsable.

Si vous avez un doute → vérifiez la valeur avant de soumettre.

---

#### Fermentation

**Où :** Menu → **Fermentation**

Le formulaire de fermentation enregistre l'évolution d'un lot pendant toute sa garde en cuve.

##### Quatre types d'événements

| Type | Quand le saisir |
|---|---|
| **Mesures** | À chaque relevé de densité, pH ou température |
| **Houblonnage à froid** | Lors de chaque ajout de houblon en cuve froide |
| **Purge** | Lors des purges de levure (fond de cuve) |
| **Cold Crash** | Quand le refroidissement forcé commence |

##### Trouver un lot

1. **Ouvrez** la page Fermentation.
2. La liste des lots en fermentation s'affiche.
3. Si un lot n'apparaît pas dans la liste → vérifiez que le formulaire de brassage correspondant a bien été soumis.

##### Saisir un relevé de mesures

1. Appuyez sur le lot concerné.
2. Choisissez **"Mesures"** comme type d'événement.
3. Saisissez :

| Champ | Ce qu'on saisit | Notes |
|---|---|---|
| **Date / Heure** | Moment exact du relevé | |
| **Densité °Plato** | Lecture du réfractomètre ou densimètre | |
| **pH** | Lecture du pH-mètre | Optionnel si non mesuré |
| **Température** | Température de la cuve en °C | |

4. **Soumettez.**

> Saisissez chaque relevé même si les mesures sont rapprochées dans le temps. Le système agrège lui-même et construit la courbe de fermentation. Ne "moyennez" pas deux relevés pour n'en saisir qu'un.

##### Saisir un houblonnage à froid

1. Choisissez le lot.
2. Choisissez **"Houblonnage à froid"** comme type.
3. Saisissez le houblon (type, quantité), la date et l'heure.
4. **Soumettez.**

##### Saisir une purge de levure

1. Choisissez le lot.
2. Choisissez **"Purge"** comme type.
3. Saisissez la date et l'heure, et si possible le volume purgé.
4. **Soumettez.**

##### Saisir le Cold Crash

Le Cold Crash est l'étape de refroidissement forcé qui prépare le lot au transfert. C'est une étape critique pour la suite du process.

1. Choisissez le lot.
2. Choisissez **"Cold Crash"** comme type.
3. Cochez la case de confirmation et saisissez la date/heure de début.
4. **Soumettez.**

> **Attention critique :** le Cold Crash clôt la fermentation et déclenche le compteur de garde. Un lot dont le Cold Crash n'est pas coché **n'apparaîtra pas** dans la liste de la page Transferts. Si un lot est prêt à être transféré mais n'apparaît pas → vérifiez en premier que le Cold Crash est bien enregistré.

##### Garde-fous

L'application affiche des avertissements pour les valeurs hors plage (ex. densité finale anormalement haute, température trop basse). Ces avertissements ne bloquent pas la soumission.

---

#### Transferts

**Où :** Menu → **Transferts**

Cette page gère les transferts de bière entre cuves : centrifugation, filtration KZE, mise en service (BBT, CCT).

> **Note de nomenclature :** le fichier technique s'appelle "racking" mais ne cherchez pas ce terme dans l'interface. Pour l'opérateur, c'est simplement la page **Transferts**.

##### Lots éligibles

La page affiche les **cartes de lots éligibles** : ceux qui ont atteint leur durée de garde ET sont en cuve. Seuls ces lots sont proposés.

Si un lot attendu n'apparaît pas :

1. Vérifiez que le **Cold Crash** est bien enregistré dans Fermentation.
2. Vérifiez que la durée de garde minimale est atteinte. Si vous pensez qu'une exception est justifiée, demandez à un responsable d'activer le mode **Hors process** (voir ci-dessous).

##### Étapes de saisie

1. **Ouvrez** la page Transferts.
2. **Sélectionnez** la carte du lot à transférer.
3. Remplissez le formulaire :

| Champ | Ce qu'on saisit | Notes |
|---|---|---|
| **Machines utilisées** | Cases à cocher : Centrifugeuse, KZE, Pompe, Centri+KZE | Cocher au moins une |
| **Cuve de destination** | BBT, CCT, YT | Choisir dans la liste |
| **N° de la cuve** | Numéro identifiant la cuve d'arrivée | |
| **Volume résiduel en cuve d'arrivée** | Ce qui se trouvait déjà dans la cuve avant ce transfert | Saisir 0 si cuve vide |
| **Pertes** | Volume de pertes exceptionnelles | Pertes standard = automatiques, ne pas les saisir ici |
| **Transfert interrompu** | Case à cocher si le transfert a été arrêté en cours | Obligatoire d'expliquer si coché |
| **Notes** | Commentaire libre | |

4. **Soumettez.**

##### Volume résiduel : attention

Si la cuve de destination contenait déjà de la bière (blend, reste d'un lot précédent), saisissez le volume résiduel. S'il n'y avait rien, saisissez 0. Ne laissez pas ce champ vide.

##### Pertes : ne saisir que les pertes exceptionnelles

Les pertes standards liées au process (pertes de centrifugation, etc.) sont calculées automatiquement. La section Pertes du formulaire sert uniquement aux pertes **exceptionnelles** (casse, incident, spill). Ne doublez pas les pertes en saisissant les pertes standard ici.

##### Mode Hors process

> **Réservé aux managers et admins uniquement.**
>
> Ce mode déverrouille les lots normalement inéligibles (garde non atteinte, Cold Crash absent, cuve non renseignée) et exige une raison écrite. Il est utilisé pour gérer les cas exceptionnels justifiés.
>
> Si vous pensez avoir besoin de ce mode, **contactez votre responsable**. Ne tentez pas de le contourner.

---

#### Conditionnement

**Où :** Menu → **Conditionnement**

Le formulaire de conditionnement enregistre chaque session de mise en emballage : bouteilles, canettes, fûts, cuves de service.

C'est également le formulaire qui **alimente directement le stock de produits finis**. Chaque run de conditionnement saisi ici augmente le Stock PF du montant produit.

##### Types de runs disponibles

| Type de run | Formats possibles |
|---|---|
| **Run bouteille 33cl** | Carton 6×4, box 24, bouteille unitaire, cage / vrac |
| **Run canette 50cl** | Canette unitaire, box 24 |
| **Run canette 33cl** | Canette unitaire, box 24 |
| **Fût 20L** | Fût |
| **Cuve de service** | Cuve de service |

##### Suffixes de format

Dans les références SKU et dans le formulaire, les formats sont identifiés par des suffixes :

| Suffixe | Format complet |
|---|---|
| **4** | Carton 6×4 (24 bouteilles) |
| **B** | Box 24 (canettes ou bouteilles) |
| **F** | Fût 20L |
| **V** | Cuve de service |
| **C** | Canette unitaire |
| **BU** | Bouteille unitaire |
| **CU** | Canette unitaire (format alternatif) |
| **X** | Cage / vrac de bouteilles |

##### Étapes de saisie

1. **Ouvrez** la page Conditionnement.
2. **Choisissez le lot source** dans la liste (lots en cuve BBT/CCT éligibles).
3. **Choisissez le type de run** (bouteille, canette, fût, cuve de service).
4. **Choisissez le format** dans la liste.
5. **Saisissez les mesures CO₂ et O₂** avant remplissage.
   - Ces mesures sont prises une seule fois par lot par jour.
   - Si elles ont déjà été saisies aujourd'hui pour ce lot, le formulaire les reprend automatiquement. Vous n'avez pas à les ressaisir.
6. **Saisissez la quantité** produite pour ce format.
7. Si nécessaire, complétez les **dispositions** (voir tableau complet en Annexe E).
8. **Soumettez.**

##### Formats parallèles : comment faire

Si la même session couvre plusieurs formats en parallèle (ex. vous embouteillez des cartons 6×4 ET des box 24 du même lot le même jour) :

1. Remplissez la **première ligne** avec son format et sa quantité.
2. Appuyez sur **"+ Ajouter un format parallèle"**.
3. Remplissez la **deuxième ligne** avec son propre format et sa propre quantité.
4. Répétez si nécessaire pour un troisième format.

> **Ne saisissez jamais le total sur la ligne principale.** Chaque ligne porte uniquement sa propre quantité. Si vous saisissez le total sur la ligne principale ET les détails sur les lignes parallèles, vous doublez la production.

> **Une autre bière = une autre session.** Ne mélangez jamais deux références (deux bières différentes) dans la même saisie. Si vous conditionnez Zepp et Embuscade le même jour, faites deux sessions séparées, une pour chaque.

##### White label

Pour une production sous label client (bière conditionnée pour un tiers sous son propre label) :

1. Activez le switch ou la case **"White label"** sur la ligne de format.
2. Choisissez le client dans la liste déroulante.

##### Cage / coffret

Pour les cages de bouteilles ou le vrac en caisse bois :

- Choisissez le format **X (cage / vrac)** dans la liste des formats.
- Saisissez le nombre de bouteilles (pas le nombre de cages).

##### Mesures CO₂ et O₂

Ces mesures de qualité sont importantes pour la traçabilité. Elles sont prises **avant** le début du remplissage pour le lot, une fois par journée de conditionnement.

| Mesure | Unité | Quand |
|---|---|---|
| **CO₂** | g/L ou volumes | Avant premier remplissage |
| **O₂** | ppb ou mg/L | Avant premier remplissage |

Si vous avez oublié de prendre ces mesures avant de commencer, prenez-les en cours de run et indiquez-le en note.

##### Dispositions : vue d'ensemble

Les dispositions enregistrent les unités qui sortent du circuit vendable. Elles sont décrites en détail en **Annexe E**.

En résumé : utilisez les dispositions pour toute unité produite mais non vendue (casse, pertes, QA, taproom). Le **volume vendable** est calculé automatiquement à partir des dispositions — ne le saisissez pas vous-même.

##### Soumission et confirmation

Après soumission, la page affiche un résumé de ce qui a été enregistré. Vérifiez :

- La référence (bière + format) est-elle correcte ?
- La quantité correspond-elle à ce que vous avez produit ?
- Les dispositions sont-elles correctes ?

En cas d'erreur sur un run déjà soumis, contactez un manager ou un administrateur.

---

### 3.2 Réceptionner une livraison (Approvisionnement)

**Où :** Menu → **Approvisionnement**

Cette section affiche les informations sur les fournisseurs et les livraisons de matières premières (malts, houblons, adjuvants, emballages, etc.).

#### Ce que vous pouvez faire (opérateurs)

- **Consulter** les fiches fournisseurs.
- **Vérifier** l'historique des livraisons d'un fournisseur.
- **Voir** l'état des commandes en cours (reçues, en attente, etc.).
- **Consulter** les documents associés aux livraisons (bons de livraison, factures).

#### Ce que vous ne pouvez pas faire (réservé managers/admins)

- Créer ou modifier une fiche fournisseur.
- Saisir une nouvelle livraison manuellement.
- Modifier les prix ou les données de confiance.
- Valider une livraison dans le système.

> **Si vous recevez physiquement une livraison :** vérifiez visuellement la marchandise (bon de livraison, quantités, état des sacs/colis), puis informez votre responsable. C'est lui qui valide la livraison dans le système. Ne tentez pas de la saisir vous-même.

#### Consulter l'état d'une livraison attendue

1. **Ouvrez** Approvisionnement.
2. **Cherchez le fournisseur** dans la liste ou via la barre de recherche.
3. Ouvrez la fiche du fournisseur.
4. Consultez l'historique des livraisons et les commandes en cours.

---

### 3.3 Préparer et expédier les commandes

#### Vue Commandes (B2B)

**Où :** Expéditions → **Commandes**

Cette vue liste les commandes B2B récentes avec leur statut et leurs lignes de produits.

##### Un point important sur la création de commandes B2B

La **création manuelle de commandes B2B est désactivée** dans l'application. Les commandes B2B proviennent de Business Central (le système comptable de La Nébuleuse). Vous ne pouvez pas en créer depuis l'application.

Vous pouvez en revanche :
- Consulter les commandes existantes et leurs lignes.
- Faire avancer le statut d'une commande (de Confirmée vers Préparée, etc.).
- Créer des **commandes internes** (Taproom, Boutique en ligne, Cage, Shop) — voir la section "Saisir une commande interne".

##### Trouver une commande

- Les commandes récentes apparaissent dans la liste.
- Utilisez la barre de recherche ou les filtres (par statut, par client, par date) pour retrouver une commande spécifique.
- Appuyez sur une commande pour voir ses détails.

##### Détail d'une commande

Le détail d'une commande montre :

| Information | Description |
|---|---|
| **Client** | Nom du client destinataire |
| **Date de livraison** | Date prévue |
| **Site d'expédition** | D'où part la commande |
| **Transporteur** | Transporteur prévu (si renseigné) |
| **Lignes** | Références SKU commandées avec quantités |
| **Statut actuel** | Étape actuelle de la commande |
| **Historique** | Journal des changements de statut |

#### Avancer le statut d'une commande

Les commandes B2B suivent une progression de statuts. Chaque changement de statut est une action que l'opérateur fait manuellement après avoir accompli l'action correspondante.

| De ce statut | Vers ce statut | Quand le faire |
|---|---|---|
| **Saisie** | **Confirmée** | Quand la commande est vérifiée et validée |
| **Confirmée** | **Préparée** | Quand les colis sont physiquement préparés |
| **Préparée** | **BL imprimé** | Quand le bon de livraison est imprimé |
| **BL imprimé** | **Livrée** | Quand le transporteur a pris en charge |

##### Étapes pour avancer un statut

1. **Ouvrez** la commande dans la vue Commandes.
2. Vérifiez les lignes : références et quantités correspondent-elles à ce que vous préparez ?
3. Appuyez sur le bouton de statut suivant (ex. **"Marquer comme Préparée"**).
4. Si une boîte de dialogue de confirmation apparaît, confirmez.
5. Le statut est mis à jour immédiatement.

> Ne sautez pas d'étapes. Passez toujours dans l'ordre : Confirmée → Préparée → BL imprimé → Livrée. Les étapes sont tracées pour la comptabilité et la logistique.

##### Commandes Annulées

Une commande Annulée ne peut pas être réactivée par un opérateur. Si une commande a été annulée par erreur, contactez un manager.

---

#### Boutique en ligne (commandes e-shop)

**Où :** Expéditions → **Boutique en ligne**

Cette vue affiche les commandes passées sur le site e-shop de La Nébuleuse (via Shopify). Elle se rafraîchit automatiquement au fil des nouvelles commandes.

##### Statuts des commandes e-shop et leur signification

| Statut | Ce que ça veut dire | Action opérateur |
|---|---|---|
| **Nouveau** | Commande reçue, non traitée | Commencer la préparation |
| **En préparation** | Picking en cours | Continuer le picking |
| **Préparé** | Colis prêt, pas encore expédié | Expédier ou prévenir pour retrait |
| **Prêt au retrait** | Client notifié, attend de venir chercher | Attendre le client |
| **Expédié** | Colis remis au transporteur | Aucune action |
| **Remis** | Remis en main propre au client | Aucune action |
| **Annulé** | Commande annulée | Remettre en stock si préparé |

##### Workflow pour une commande de livraison postale (La Poste, autre transporteur)

1. Commande arrive en **Nouveau**.
2. Commencez la préparation → passez en **En préparation**.
3. Colis prêt → passez en **Préparé**.
4. Imprimez le bordereau de transport.
   - L'application peut automatiquement passer la commande en **Expédié** à l'impression du bordereau.
5. Si l'avancement automatique n'a pas eu lieu, confirmez manuellement **Expédié** après remise au transporteur.

##### Workflow pour un retrait en boutique (click & collect)

1. Commande arrive en **Nouveau**.
2. Préparez le colis → passez en **En préparation** puis **Préparé**.
3. Quand le colis est prêt → passez en **Prêt au retrait** et informez le client.
4. Quand le client vient chercher et repart avec son colis → passez en **Remis**.

> Le passage en **Remis** est **toujours manuel** pour les retraits. Le système ne peut pas savoir que le client est passé sans que vous le saisissiez.

##### Note sur les colis multi-références

Si une commande e-shop contient plusieurs références (ex. un carton de Zepp + une bouteille d'Embuscade), toutes les lignes doivent être préparées et cochées avant de passer en Préparé. Vérifiez systématiquement le détail de la commande avant de changer le statut.

---

#### Commandes reçues par e-mail

**Où :** Menu → **Commandes e-mail**

> **Accès :** managers logistique et admins uniquement. Si vous recevez un e-mail de commande, transmettez-le à votre responsable.

Pour référence, voici comment fonctionne ce module (vous pouvez avoir à l'expliquer à un client ou à un collègue) :

Le système analyse automatiquement les e-mails de commande entrants et tente d'en extraire les informations clés : client, références SKU, quantités, date souhaitée.

L'écran est divisé en trois volets :

| Volet | Contenu |
|---|---|
| **À valider** | Commandes parsées automatiquement, à confirmer avant création |
| **Non parsé** | E-mails non reconnus, à traiter manuellement par le manager |
| **Créées** | Commandes déjà créées depuis un e-mail |

Règles importantes pour les managers :

- Avant de valider, vérifiez : client correct ? Référence correcte ? Quantité correcte ? Date de livraison raisonnable ?
- Si une ligne est manquante ou douteuse → la validation est bloquée. Le système refuse les commandes partielles.
- Si une commande similaire existe déjà, le système avertit avant la création.
- Ne créez pas de doublon manuellement si le système avertit d'un doublé.

---

#### Saisir une commande interne

**Où :** Expéditions → **Saisie commande**

Les commandes internes (pour les canaux Taproom, Boutique en ligne, Cage, Shop) peuvent être créées directement dans l'application par les opérateurs habilités.

##### Quand créer une commande interne

- Commande taproom (vente sur place non passée par Shopify).
- Commande cage / coffret.
- Commande boutique physique (Shop).
- Toute commande interne à La Nébuleuse (repas d'équipe, événement, etc.).

##### Étapes

1. **Ouvrez** Expéditions → Saisie commande.
2. Remplissez les champs :

| Champ | Ce qu'on saisit | Notes |
|---|---|---|
| **Type** | Commande interne | Sélectionner "Commande interne" |
| **Canal** | Taproom / Boutique en ligne / Cage / Shop | Choisir le bon canal |
| **Client** | Choisir dans la liste existante | |
| **Nouveau client** | Saisir le nom si le client n'existe pas encore | |
| **Date de livraison souhaitée** | Date attendue pour la livraison ou la remise | |
| **Transporteur** | Optionnel — laisser vide si livraison interne | |
| **Site d'expédition** | Optionnel — laisser vide = sélection automatique | |

3. **Ajoutez les lignes de produits** :
   - Appuyez sur **"Ajouter une ligne"** ou le bouton équivalent.
   - Choisissez la référence SKU.
   - Saisissez la quantité.
   - Ajoutez un commentaire si nécessaire (ex. "format spécifique demandé", "pour l'événement du 5 juillet").
   - Répétez pour chaque référence.

4. **Vérifiez** le récapitulatif.

5. **Soumettez** la commande.

> Un commentaire par ligne est utile pour préciser des détails opérationnels. Il sera visible de toute personne qui consulte la commande ensuite.

##### Modifier une commande interne après soumission

Si vous avez besoin de modifier une commande interne après soumission (quantité, date, ligne à ajouter), contactez un manager. Les modifications post-soumission sont réservées aux managers et admins.

---

## 4. L'hebdomadaire

### 4.1 Comptage Stock PF

**Où :** Expéditions → **Comptage Stock PF**

Le comptage Stock PF est votre action hebdomadaire la plus importante. Sans comptage régulier, les écarts s'accumulent et les chiffres deviennent peu fiables.

**Fréquence recommandée :** au moins une fois par semaine, de préférence en fin de semaine (vendredi soir ou samedi matin).

#### Avant de commencer

- Choisissez un moment où les mouvements de stock sont calmes (pas au milieu d'une expédition en cours).
- Comptez avant d'expédier plutôt qu'après, pour avoir un instantané propre.
- Préparez un papier et un stylo : il est plus facile de noter les quantités en comptant les palettes, puis de les saisir dans l'application.
- Assurez-vous d'avoir accès à toutes les zones de stockage du site.

#### Étapes de saisie

1. **Ouvrez** Expéditions → Comptage Stock PF.

2. **Choisissez le site** que vous comptez.
   - Vous comptez un site à la fois.
   - Si vous êtes responsable de plusieurs sites, faites un comptage séparé pour chacun.

3. **Vérifiez ou saisissez la date du comptage.**
   - Par défaut, c'est la date du jour.
   - Les opérateurs ne peuvent comptabiliser qu'à la date du jour.
   - Les managers et admins peuvent antidater (utile pour rattraper un comptage oublié).

4. **Case "Clôture mensuelle" :**
   - Si ce comptage correspond à la fin d'un mois comptable → cochez cette case.
   - Sinon → laissez-la décochée.
   - En cas de doute, demandez à votre responsable.

5. **Parcourez toutes les références SKU** ligne par ligne.

   Pour chaque ligne :

   | Situation | Que saisir |
   |---|---|
   | Vous avez compté et le stock est positif | La quantité exacte |
   | Vous avez compté et le stock est à zéro | **0** |
   | Vous n'avez pas compté cette référence | Laisser vide |

6. **Soumettez** le formulaire.

   Un message de confirmation s'affiche. Le système recalcule immédiatement le Stock PF pour ce site à partir de ce nouveau comptage.

#### Règle fondamentale : un comptage est un recensement COMPLET

Le comptage Stock PF remet le stock du site à **exactement ce que vous saisissez** — **rien n'est reporté** de l'ancien comptage.

- Une case **vide** et une case à **0** produisent le **même résultat** : la référence est mise à **0** pour ce site.
- Toute référence **non saisie** passe donc à **0**. C'est pourquoi vous devez compter **toutes** les références présentes à chaque comptage.
- ⚠️ **Danger — un comptage partiel met à zéro tout ce que vous n'avez pas saisi.** Si vous ne comptez que les fûts et laissez les bouteilles vides, les bouteilles tombent à **0** sur le tableau. Ne soumettez un comptage que lorsqu'il est **complet** pour ce site.

> **Exemple concret :** vous comptez l'entrepôt. Vous cherchez les ZEPF (Zepp Fût 20L) : il y en a **12 pleins et vendables**. Saisissez **12** — comptez **uniquement les fûts pleins et vendables**, jamais les fûts vides ni ceux déjà partis chez les clients (c'est la cause n°1 d'un stock gonflé et faux). Si une référence n'est réellement pas présente sur le site, laissez-la à 0 — à condition que votre comptage couvre bien **tout** le site.

#### Cas particuliers

##### Comptage sur plusieurs sessions

Si le comptage d'un site est grand et nécessite plusieurs sessions (matin / après-midi, ou plusieurs jours) :

- La première session soumet un comptage partiel (les zones déjà comptées).
- Les sessions suivantes complètent les zones restantes.
- Le système agrège les saisies.

Conseil : notez sur un plan de l'entrepôt quelles zones ont été comptées à chaque session.

##### Emballages partiels

Pour les cartons entamés : comptez les **unités** individuelles, pas les cartons. Exemple : 3 cartons pleins de 24 + 1 carton entamé avec 16 bouteilles = 88 bouteilles.

##### Écart important constaté

Si lors d'un comptage vous trouvez un écart significatif entre ce que le système affiche et ce que vous comptez physiquement :

1. **Recomptez** pour confirmer.
2. Si l'écart est confirmé → **signalez à votre responsable avant de soumettre**.
3. Décrivez l'écart (référence, quantité système vs quantité comptée, zone de stockage).
4. Votre responsable peut enquêter (production non saisie, transfert manquant, etc.) avant de valider le comptage.
5. Une fois l'enquête faite → soumettez le comptage réel.

> Ne "corrigez" jamais un écart en saisissant la valeur que vous attendiez plutôt que ce que vous avez compté. Le comptage doit refléter la réalité physique.

---

### 4.2 Comptage Matières Premières

**Où :** Menu → **Inventaire MP**

Le comptage des matières premières (malts, houblons, adjuvants, emballages, produits de nettoyage, etc.) alimente les calculs de coûts de production.

**Fréquence recommandée :** au moins une fois par mois, idéalement en fin de mois. Certains ingrédients critiques peuvent justifier un comptage hebdomadaire.

#### Comment fonctionne ce formulaire (différent du Stock PF)

Contrairement au formulaire de comptage Stock PF, ce formulaire **sauvegarde chaque ligne immédiatement** quand vous appuyez sur "Ajouter". Il n'y a pas de bouton "Soumettre" global.

- Vous pouvez quitter et revenir : ce que vous avez déjà saisi est conservé.
- Vous pouvez compter dans n'importe quel ordre.
- Vous pouvez prendre des pauses.

#### Étapes

1. **Ouvrez** Inventaire MP depuis le menu.

2. **Vérifiez la période** en haut du formulaire.
   - Par défaut, c'est le mois en cours.
   - Pour compter un autre mois, modifiez la période (si vous êtes habilité à le faire).

3. **Cherchez l'ingrédient** à compter dans le champ de recherche.
   - Tapez les premières lettres du nom ou de la référence.
   - La liste se filtre automatiquement (type-ahead).
   - Choisissez le bon ingrédient dans la liste.

4. **Saisissez la quantité** comptée (en kg, g, L, unités — selon l'ingrédient).

5. Appuyez sur **"Ajouter"** — la ligne est sauvegardée immédiatement.
   - Un message de confirmation bref s'affiche.

6. Répétez pour chaque ingrédient.

#### Vide ≠ zéro (même règle qu'en Stock PF)

Si un ingrédient est épuisé, saisissez **0** et appuyez sur "Ajouter". Ne laissez pas un ingrédient de côté en pensant que "ça sera à zéro automatiquement".

Un ingrédient non saisi lors d'un comptage conserve la valeur du comptage précédent dans les calculs.

#### Comptez palette par palette

Pour les gros stocks (malts, palettes de houblon), comptez sac par sac ou palette par palette. Les estimations "à la louche" (ex. "environ 500 kg") introduisent des erreurs dans les calculs de coûts qui peuvent se répercuter sur les marges.

#### En cas de doute sur l'identité d'un ingrédient

Si le libellé d'un sac ou d'un contenant ne correspond pas clairement à un ingrédient dans la liste → **demandez à votre responsable avant de saisir**. Ne cherchez pas à "deviner" l'ingrédient le plus proche. Une mauvaise attribution dans l'inventaire des matières premières peut fausser les coûts de production.

---

## 5. Au besoin

### 5.1 Retours et avoirs

**Où :** Expéditions → **Retours & avoirs**

Cette vue permet de saisir les retours physiques de marchandises lorsqu'un client retourne de la bière.

#### Pré-requis : le numéro d'avoir

Tout retour doit être associé à un **numéro d'avoir Business Central** (BC). Ce numéro est émis par le service comptable quand un avoir est accordé à un client.

Sans numéro d'avoir → le système bloque la saisie. Contactez la comptabilité pour obtenir ce numéro avant de commencer.

#### Quand utiliser ce formulaire

- Un client retourne des bouteilles défectueuses ou non conformes.
- Une commande a été partiellement livrée et le solde est retourné.
- Une livraison a été refusée à la réception par le client.

#### Étapes

1. **Ouvrez** Expéditions → Retours & avoirs.

2. **Saisissez le numéro d'avoir** Business Central dans le champ prévu.

3. Le système **retrouve automatiquement** les lignes associées à cet avoir depuis le grand livre :
   - Références SKU concernées.
   - Quantités correspondantes.
   - Client d'origine.

4. **Vérifiez** que les lignes affichées correspondent à ce que vous avez physiquement reçu.
   - Si une ligne ne correspond pas, prévenez votre responsable avant de continuer.

5. Pour chaque ligne, **choisissez la disposition** de la marchandise retournée :

   | Disposition | Quand l'utiliser | Effet sur le stock |
   |---|---|---|
   | **Remise en stock** | Produit en bon état, emballage intact, propre | Réintégré au stock vendable |
   | **Casse** | Produit détérioré, cassé, inutilisable | Éliminé définitivement |
   | **Quarantaine** | Doute sur l'état — à analyser avant décision | Mis de côté, hors stock vendable |

6. **Soumettez** le retour.

Un message de confirmation s'affiche. Le stock est mis à jour immédiatement pour les lignes en "Remise en stock".

#### Après soumission

- Les produits en **Remise en stock** réintègrent le Stock PF du site correspondant.
- Les produits en **Quarantaine** font l'objet d'une décision ultérieure par le responsable qualité. Physiquement, mettez-les dans la zone de quarantaine de votre entrepôt avec une étiquette claire.
- Les produits en **Casse** doivent être éliminés physiquement (recyclage, destruction selon procédure).

#### En cas de désaccord

Si ce que vous avez physiquement reçu ne correspond pas à ce que le système affiche (ex. quantités différentes, références différentes) → **contactez votre responsable avant de soumettre**. Ne modifiez pas les quantités à la main pour faire "coller" — cela masque un écart réel entre la comptabilité et le physique.

---

### 5.2 Déballage / Assemblage

**Où :** Expéditions → **Déballage / Assemblage**

Cette vue affiche des propositions de déballage de packs pour honorer des commandes e-shop qui nécessitent des formats non directement en stock.

#### Ce que c'est

Parfois, le stock disponible est en cartons de 24 ou en box, mais une commande e-shop demande des bouteilles à l'unité ou des packs spéciaux. La vue Déballage / Assemblage propose les déballages à faire pour satisfaire ces commandes.

#### Ce que c'est **pas**

- Ce n'est pas un outil de saisie de production.
- Il n'enregistre pas directement un mouvement de stock.
- C'est une vue **advisory** : elle conseille, elle n'agit pas.

#### Comment l'utiliser

1. **Ouvrez** Expéditions → Déballage / Assemblage.

2. Consultez les **propositions affichées** :
   - Quelle référence déballer.
   - Depuis quel format (ex. carton de 24 → unités).
   - Quelle quantité ouvrir.

3. **Réalisez physiquement** le déballage selon les propositions.

4. **Confirmez** via le bouton prévu si votre version de l'application le propose.

5. Si aucun bouton de confirmation n'est visible → informez votre responsable que le déballage a été fait pour qu'il puisse mettre à jour le stock.

#### En cas de proposition incohérente

Si une proposition vous semble incorrecte (ex. déballer plus que ce que vous avez en stock) → ne faites pas le déballage et prévenez votre responsable.

---

### 5.3 Transferts inter-sites

**Où :** Expéditions → **Transferts inter-sites**

> **Point crucial :** cet écran est un **journal d'événements**, pas un tableau de stock. Il liste les transferts qui ont eu lieu — il ne montre pas les soldes.

#### Quand l'utiliser

Vous déplacez physiquement des produits finis d'un site à un autre :
- De l'entrepôt (Zgeg) vers le taproom (Le Zeppelin).
- Entre deux sites de stockage.
- Réapprovisionnement d'un site depuis un autre.

Ces mouvements doivent être enregistrés pour que le Stock PF de chaque site reste juste.

#### Étapes pour saisir un transfert

1. **Ouvrez** Expéditions → Transferts inter-sites.

2. Appuyez sur **"Nouveau transfert"** ou le bouton équivalent.

3. Remplissez le formulaire :

| Champ | Ce qu'on saisit | Notes |
|---|---|---|
| **Site d'origine** | D'où part la marchandise | Choisir dans la liste |
| **Site de destination** | Où elle arrive | Choisir dans la liste |
| **Date du mouvement** | Date réelle du transfert | Par défaut = aujourd'hui |
| **Commentaire** | Optionnel, mais utile | Ex. : "réapprovisionnement semaine 26", "urgence pour événement" |
| **Lignes SKU** | Une ligne par référence | Référence + quantité entière |

4. **Ajoutez les lignes** de produits :
   - Appuyez sur "Ajouter une ligne".
   - Choisissez la référence SKU.
   - Saisissez la quantité (nombre entier, sans décimales).
   - Répétez pour chaque référence.

5. **Vérifiez** le récapitulatif avant de soumettre.

6. **Soumettez** le transfert.

#### Règles importantes

- La **date du mouvement** doit correspondre à la date réelle du transfert physique, pas à la date de saisie si elles diffèrent.
- Les **quantités** doivent être des entiers (pas de 0,5 bouteille).
- **Un transfert enregistré ne peut être annulé que par un administrateur.** En cas d'erreur, contactez immédiatement un administrateur avant que le stock ne soit trop impacté.
- Ne saisissez un transfert que si le déplacement physique a bien eu lieu ou va avoir lieu le jour même. Ne saisissez pas de transferts prévisionnels futurs.

#### Lire le journal des transferts

Le journal affiche les transferts passés avec :

| Colonne | Information |
|---|---|
| **Date** | Date du transfert |
| **Origine** | Site de départ |
| **Destination** | Site d'arrivée |
| **Références** | SKU et quantités transférées |
| **Commentaire** | Note de l'opérateur |
| **Saisi par** | Nom de l'opérateur |

Ce journal est utilisé pour reconstituer l'historique en cas de litige ou d'enquête sur un écart.

---

### 5.4 Stock d'accompagnement

**Où :** Expéditions → **Stock d'accompagnement**

Cette vue suit les restes d'emballage ou de produits mis de côté hors du circuit normal de vente.

#### Exemples d'utilisation

- Produits réservés pour un événement ou une dégustation de presse.
- Échantillons envoyés à des partenaires ou des médias.
- Lots mis de côté en attente d'une décision (ex. doute sur la qualité).
- Giveaways remis directement sans commande formelle.
- Produits utilisés en interne (réunion d'équipe, bière d'essai).

#### Ce que ce stock n'est pas

Ce n'est pas du stock vendable. Ces produits ne sont pas inclus dans les semaines de couverture et ne génèrent pas de commandes. C'est une zone de traçabilité pour ce qui sort du circuit normal mais doit rester traçable.

#### Saisir un article

1. **Ouvrez** Expéditions → Stock d'accompagnement.

2. Appuyez sur **"Ajouter"**.

3. Remplissez :

| Champ | Ce qu'on saisit | Notes |
|---|---|---|
| **Référence SKU** | La référence du produit mis de côté | |
| **Quantité** | Nombre d'unités | En unités entières |
| **Note** | Explication obligatoire | Ex. : "giveaway événement 4 juillet", "lot mis en quarantaine QA", "dégustation journalistes" |

4. **Sauvegardez.**

> La note est importante. Sans note, personne ne sait pourquoi ce stock est là. Soyez précis.

#### Supprimer ou modifier un article

Les modifications et suppressions dans le stock d'accompagnement sont réservées aux managers. Si vous avez saisi une erreur, signalez-la à votre responsable.

---

## 6. Lire le tableau de stock

**Où :** Expéditions → **Stock PF par site**

Ce tableau est votre outil de pilotage quotidien. Il montre l'état du stock de produits finis à l'instant présent.

### 6.1 Structure du tableau

Le tableau présente, pour chaque référence SKU et chaque site :

| Colonne | Ce qu'elle montre |
|---|---|
| **Référence** | Code SKU (ex. ZEPF, EMB4, ZEPC…) |
| **Désignation** | Nom lisible (ex. "Zepp Fût 20L", "Embuscade Carton 6×4") |
| **Site** | Lieu de stockage |
| **Stock physique** | Quantité calculée en temps réel |
| **Semaines de couverture** | Estimation du temps avant rupture |
| **Alerte** | Code couleur et libellé selon le niveau de stock |

### 6.2 Les alertes et leur signification opérationnelle

#### Bien fourni (10 semaines et plus)

Le stock est confortable. Pas d'action immédiate nécessaire. À surveiller en tendance (si la consommation accélère).

#### Bas (moins de 3 semaines)

Attention : le stock descend. Si un brassage n'est pas déjà en cours, c'est le moment d'alerter le responsable de production. Vérifiez également que les comptages récents sont à jour — un "Bas" peut parfois être dû à un comptage trop ancien.

#### Critique (moins de 1 semaine)

Situation urgente. Il faut :
1. Informer immédiatement le responsable de production.
2. Vérifier s'il y a du stock en cours de conditionnement non encore saisi.
3. Arbitrer les commandes ouvertes si nécessaire (avec votre responsable).

#### Survendu

Les commandes ouvertes dépassent le stock disponible. **Ce n'est pas une erreur système.** C'est un signal d'alerte qui indique :

- Soit que la production doit être accélérée.
- Soit que certaines commandes doivent être décalées ou arbitrées.
- Soit qu'un comptage ou un run de conditionnement a été oublié (vérifiez les saisies).

En cas de survendu, signalez à votre responsable. Ne modifiez pas le stock pour faire disparaître l'alerte.

### 6.3 Chiffres négatifs

Un stock négatif peut apparaître dans certaines situations. Les causes les plus fréquentes :

| Cause | Que faire |
|---|---|
| Comptage en retard | Effectuer un comptage immédiatement |
| Run de conditionnement non saisi | Retrouver la session manquante et la saisir |
| Transfert inter-sites non enregistré | Saisir le transfert manquant |
| Expéditions saisies en double | Signaler à un manager |

> En cas de stock négatif, **ne corrigez pas en gonflant un comptage**. Cherchez la cause réelle avec votre responsable. Corriger la cause corrige le stock.

### 6.4 Limitation connue — Boutique en ligne

> **Information importante à connaître :** certaines ventes via la boutique en ligne (notamment les packs multi-formats et certains formats spéciaux) peuvent actuellement ne pas décrémenter automatiquement le Stock PF en temps réel. C'est une **limitation connue** et en cours de correction.
>
> Le comportement normal et attendu est : chaque vente e-shop doit réduire le stock immédiatement.
>
> **En pratique, si vous constatez qu'une référence vendue en boutique en ligne semble surévaluée par rapport à la réalité physique :**
> - Signalez-le à votre responsable.
> - Ne compensez pas par un comptage artificiellement bas.
> - Le comptage hebdomadaire reste le meilleur filet de sécurité contre ces écarts temporaires : il réancre les stocks une fois par semaine sur la réalité physique.

---

## 7. Bonnes pratiques et erreurs fréquentes

### 7.1 Les dix bonnes pratiques de l'opérateur

#### 1. Saisir au moment, pas après

Chaque formulaire doit être rempli au moment de l'opération ou dans les deux heures qui suivent. Les saisies "de mémoire" en fin de journée introduisent des erreurs : volume approximatif, heure incorrecte, N° de lot oublié.

#### 2. Vide ≠ zéro dans les comptages

C'est la règle la plus souvent mal comprise. Une case vide n'est pas interprétée comme "zéro" — elle est interprétée comme "non compté". Si le stock est à zéro, saisissez 0.

#### 3. Ne jamais sauter le Cold Crash

Le Cold Crash doit être enregistré pour qu'un lot apparaisse dans la liste de la page Transferts. C'est la première chose à vérifier si un lot "bloqué" n'apparaît pas.

#### 4. Signaler avant de compenser

Si vous constatez un écart entre le stock affiché et la réalité physique, signalez-le à votre responsable avant d'agir. Ne cherchez pas à "corriger" en gonflant un comptage ou en omettant une ligne.

#### 5. Compter régulièrement et complètement

Un comptage hebdomadaire par site actif est la meilleure protection contre la dérive. Comptez toujours complètement, zone par zone, palette par palette.

#### 6. Un run = une bière

Dans le conditionnement, ne mélangez jamais deux références dans la même session. Si vous conditionnez deux bières le même jour, faites deux sessions séparées.

#### 7. Quantités entières pour les transferts inter-sites

Les transferts n'acceptent pas de décimales. Comptez en unités entières.

#### 8. Toujours vérifier le détail d'une commande avant de changer le statut

Avant de passer une commande de "Confirmée" à "Préparée", relisez les lignes. Une erreur de statut sur une commande non préparée peut créer des problèmes logistiques sérieux.

#### 9. Ne pas créer de doublon e-mail

Si le système avertit qu'une commande similaire existe déjà, ne créez pas de nouvelle commande. Signalez au manager pour vérification.

#### 10. Documenter les cas inhabituels

Si vous avez fait quelque chose d'inhabituel (transfert d'urgence, retour partiel, disposition exceptionnelle en conditionnement) → ajoutez une note dans le formulaire et signalez à votre responsable. Une trace vaut mieux qu'aucune.

---

### 7.2 Erreurs fréquentes et comment les éviter

| Erreur | Impact | Comment l'éviter |
|---|---|---|
| Laisser une case vide au lieu de 0 dans un comptage | Stock non remis à zéro — écart accumulé | Toujours saisir 0 pour un stock épuisé |
| Oublier de cocher le Cold Crash | Lot bloqué dans Fermentation, invisible dans Transferts | Cocher dès la mise en froid |
| Saisir le total sur la ligne principale en conditionnement parallèle | Doublage de la production dans le stock | Chaque ligne parallèle porte sa propre quantité |
| Corriger un stock négatif par un comptage gonflé | Masque un problème réel | Chercher la cause avec un responsable |
| Créer un retour sans numéro d'avoir | Bloqué par le système | Demander le numéro à la comptabilité avant de commencer |
| Saisir des décimales dans un transfert | Erreur de soumission | Compter en unités entières |
| Mélanger deux bières dans une session de conditionnement | Attribution incorrecte de la production | Une session par bière, sans exception |
| Avancer une commande B2B sans avoir préparé le colis | Statut incorrect — problèmes logistiques | Vérifier physiquement avant de changer le statut |
| Saisir un lot dans Conditionnement avec le mauvais format | Stock attribué à la mauvaise référence | Relire la ligne avant soumission |
| Confondre "pas compté" et "zéro" | Valeur précédente conservée ou stock faussé | Comprendre la règle vide/zéro |

### 7.3 Cas particulier : limitation e-shop

Comme mentionné en section 6.4, certaines ventes e-shop peuvent ne pas décrémenter le stock en temps réel. Les bonnes pratiques associées :

- **Lors des comptages hebdomadaires :** soyez particulièrement attentifs aux références principalement vendues en boutique en ligne. Si l'écart entre le stock affiché et le stock physique est constant et similaire au volume e-shop, c'est probablement la limitation connue.
- **En cas d'alerte "Survendu" sur une référence très active en e-shop :** vérifiez d'abord si c'est la limitation en jeu avant de déclencher une alerte production.
- **Signalement :** si vous identifiez une référence spécifique concernée par cette limitation, signalez-la à votre responsable avec des détails (référence, écart estimé). Ces informations aident à prioriser la correction.

### 7.4 Que faire si quelque chose ne fonctionne pas

| Problème | Première action | Si ça ne se résout pas |
|---|---|---|
| Page blanche ou erreur d'affichage | Rafraîchissez la page (balayage vers le bas sur mobile) | Contactez votre responsable |
| Formulaire qui ne se soumet pas | Vérifiez les champs rouges (obligatoires manquants) | Contactez votre responsable |
| Lot absent de la liste dans Conditionnement | Vérifiez que le lot est en cuve BBT/CCT (transfert fait ?) | Contactez votre responsable |
| Lot absent de la liste dans Transferts | Vérifiez que le Cold Crash est enregistré | Contactez votre responsable |
| Stock négatif inexplicable | Ne corrigez pas — signalez avec détails | Responsable enquête |
| Commande B2B absente de la liste | Vérifiez les filtres (statut, date) | Contactez votre responsable |
| Impossible de se connecter | Vérifiez Wi-Fi, URL correcte (`app.maltytask.ch`) | Contactez un administrateur |

**Règle générale :** en cas de doute, ne tentez pas de contournement. Un problème signalé à temps est résolu en minutes. Un problème contourné peut générer des heures de correction de données.

---

## 8. Questions fréquentes

### Sur le stock

**Q : Pourquoi le stock affiché est différent de ce que j'ai compté ?**

Plusieurs raisons possibles :
- Le dernier comptage était il y a plus d'une semaine et de la production ou des ventes se sont produites depuis.
- Un run de conditionnement ou un transfert n'a pas été saisi.
- La limitation e-shop (section 6.4) affecte certaines références.
- Il y a eu de la casse ou une perte non déclarée.

Effectuez un nouveau comptage pour réancrer. Si l'écart persiste, signalez à votre responsable.

---

**Q : Puis-je modifier un comptage déjà soumis ?**

Non. Un comptage soumis est définitif pour les opérateurs. Seuls les managers et admins peuvent ajuster. Si vous avez soumis une valeur incorrecte, contactez votre responsable immédiatement.

---

**Q : Le stock d'un site est à zéro alors qu'il y a du produit là-bas. Que faire ?**

1. Vérifiez que le dernier comptage de ce site inclut bien cette référence avec une quantité > 0.
2. Si le dernier comptage est ancien, faites un nouveau comptage.
3. Si un run de conditionnement pour ce site n'a pas été saisi, signalez-le pour qu'il soit rattrapé.

---

**Q : Une référence affiche "Survendu". Est-ce une erreur ?**

Non. Survendu = commandes ouvertes > stock disponible. C'est un signal d'alerte commercial et de production, pas une erreur technique. Signalez à votre responsable pour arbitrage.

---

**Q : Je vois un stock négatif. Que se passe-t-il ?**

Un stock négatif signifie que le système calcule plus de sorties que d'entrées depuis le dernier comptage. Causes fréquentes : run de conditionnement non saisi, transfert manquant, comptage en retard. Ne corrigez pas avec un comptage fictif — cherchez la cause.

---

### Sur les commandes

**Q : Je ne trouve pas une commande B2B dans la liste. Pourquoi ?**

Vérifiez :
1. Le filtre de statut (la commande est peut-être dans un statut que vous avez filtré).
2. La plage de dates (si la commande est ancienne, elle peut être dans l'historique).
3. Le nom du client (vérifiez l'orthographe).

Si la commande devrait exister mais n'est nulle part → contactez votre responsable.

---

**Q : Puis-je créer une commande B2B directement dans l'application ?**

Non. Les commandes B2B viennent de Business Central (le système comptable). Seules les commandes internes (Taproom, Boutique en ligne, Cage, Shop) peuvent être créées dans l'application.

---

**Q : Une commande e-shop a été payée mais n'apparaît pas dans la vue Boutique en ligne. Que faire ?**

La synchronisation Shopify → application se fait régulièrement mais peut avoir un délai de quelques minutes. Attendez 5-10 minutes et rafraîchissez. Si la commande n'apparaît toujours pas, contactez votre responsable.

---

**Q : Comment annuler une commande interne que j'ai créée par erreur ?**

L'annulation est réservée aux managers. Contactez votre responsable avec le détail de la commande à annuler.

---

### Sur la production

**Q : Je voulais soumettre un brassage mais la recette n'est pas dans la liste. Que faire ?**

La liste ne contient que les recettes actives. Si une recette est absente, soit elle n'a pas encore été enregistrée, soit elle est désactivée. Contactez votre responsable.

---

**Q : J'ai oublié de saisir un run de conditionnement d'hier. Puis-je le saisir aujourd'hui ?**

Oui. Les formulaires acceptent une date passée pour la session. Saisissez-le le plus tôt possible avec la date correcte. Signalez à votre responsable s'il y a un écart de stock visible entre-temps.

---

**Q : Le formulaire de conditionnement m'avertit d'une valeur improbable. Que faire ?**

Lisez l'avertissement attentivement :
- Si la valeur est correcte (vous avez produit cette quantité) → soumettez et notez-le à votre responsable.
- Si vous avez un doute → revérifiez la valeur avant de soumettre.

---

**Q : Je vois un lot en Fermentation qui ne m'appartient pas. Puis-je le modifier ?**

Non. Ne modifiez jamais un lot d'un autre opérateur ou d'une autre équipe. Si vous pensez qu'il y a une erreur sur un lot qui n'est pas le vôtre, signalez-le à votre responsable.

---

### Sur les retours

**Q : Un client veut me remettre des bouteilles mais je n'ai pas le numéro d'avoir. Que faire ?**

Acceptez physiquement les bouteilles (mettez-les en quarantaine temporaire). Demandez à la comptabilité d'émettre l'avoir et de vous transmettre le numéro. Saisissez le retour dans l'application une fois le numéro obtenu. Ne saisissez jamais un retour sans numéro d'avoir.

---

**Q : Quelle est la différence entre Casse et Quarantaine ?**

- **Casse** : vous êtes certain que le produit est inutilisable et doit être éliminé. Exemple : bouteilles cassées, bière oxydée et non récupérable.
- **Quarantaine** : vous avez un doute sur l'état du produit et il faut l'analyser avant de décider. Exemple : carton mouillé mais bouteilles apparemment intactes, bière avec un goût suspect mais pas clairement mauvaise.

En cas de doute, choisissez Quarantaine plutôt que Casse. Il vaut mieux analyser et décider ensuite.

---

## Annexe A — Récapitulatif des statuts de commande

### Commandes B2B

| Statut | Signification | Ce que l'opérateur fait |
|---|---|---|
| **Saisie** | Commande enregistrée | Attendre confirmation du manager |
| **Confirmée** | À préparer | Préparer le colis |
| **Préparée** | Colis prêt | Imprimer le bon de livraison |
| **BL imprimé** | Bon de livraison imprimé | Remettre au transporteur |
| **Livrée** | Expédition confirmée | Aucune action nécessaire |
| **Annulée** | Commande annulée | Remettre en stock si déjà préparé |

### Commandes e-shop (Boutique en ligne)

| Statut | Signification | Ce que l'opérateur fait |
|---|---|---|
| **Nouveau** | Commande reçue, non traitée | Commencer la préparation |
| **En préparation** | Picking en cours | Continuer le picking, cocher les lignes |
| **Préparé** | Colis prêt | Expédier ou prévenir le client (retrait) |
| **Prêt au retrait** | Client notifié pour retrait | Attendre que le client passe |
| **Expédié** | Colis remis au transporteur | Aucune action nécessaire |
| **Remis** | Remis en main propre | Aucune action nécessaire |
| **Annulé** | Commande annulée | Remettre en stock si préparé |

### Progression normale pour une livraison postale

```
Nouveau → En préparation → Préparé → Expédié
```

### Progression normale pour un retrait

```
Nouveau → En préparation → Préparé → Prêt au retrait → Remis
```

---

## Annexe B — Accès rapide aux vues Expéditions

Toutes les vues sont accessibles depuis la section **Expéditions** du menu principal. Les URLs directes peuvent être mises en favori sur votre tablette.

| Vue | Libellé dans le menu | URL directe |
|---|---|---|
| Commandes B2B | Commandes | `/modules/expeditions.php?view=commandes` |
| Boutique en ligne | Boutique en ligne | `/modules/expeditions.php?view=shopify` |
| Saisie commande | Saisie commande | `/modules/expeditions.php?view=form` |
| Stock PF par site | Stock PF par site | `/modules/expeditions.php?view=stock` |
| Comptage Stock PF | Comptage Stock PF | `/modules/expeditions.php?view=stocktake` |
| Clients | Clients | `/modules/expeditions.php?view=clients` |
| Transferts inter-sites | Transferts inter-sites | `/modules/expeditions.php?view=mouvements` |
| Déballage / Assemblage | Déballage / Assemblage | `/modules/expeditions.php?view=repack` |
| Historique | Historique | `/modules/expeditions.php?view=historique` |
| Retours & avoirs | Retours & avoirs | `/modules/expeditions.php?view=retours` |
| Stock d'accompagnement | Stock d'accompagnement | `/modules/expeditions.php?view=side-stock` |

---

## Annexe C — Références SKU : suffixes de format

Les références SKU sont les codes compacts utilisés dans toute l'application. Ils se lisent comme : **code bière** + **suffixe format**.

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

## Annexe D — Glossaire opérateur

Ce glossaire définit les termes utilisés dans l'application et dans ce manuel. Seuls les termes opérateurs sont listés ici — pas de jargon technique.

| Terme | Définition |
|---|---|
| **SKU** | Référence d'un produit fini (ex. ZEPF = Zepp en fût). Chaque format d'une bière a son propre SKU. |
| **Stock PF** | Stock de Produits Finis. Bouteilles, canettes, fûts, cuves prêts à vendre ou livrer. |
| **Comptage** | Recensement physique des produits sur un site, à une date donnée. |
| **Semaines de couverture** | Estimation du temps avant rupture de stock, basée sur la vitesse de vente. |
| **Survendu** | Situation où les commandes ouvertes dépassent le stock disponible. |
| **Run** | Une session de conditionnement (mise en bouteille, canette, fût…). |
| **Fût 20L** | Fût de 20 litres de bière pression (appelé "keg" en anglais, jamais en opérateur). |
| **Cuve de service** | Grand contenant de bière pression pour le taproom (appelé "cuv" en technique). |
| **Lot** | Brassin identifié par la combinaison recette + N° de brassin. Tracé de A à Z. |
| **BBT** | Bright Beer Tank : cuve de bière filtrée, prête à conditionner. |
| **CCT** | Cuve de Conditionnement / de fermentation (selon contexte). |
| **Cold Crash** | Refroidissement forcé d'un lot en fin de fermentation, avant transfert. |
| **Disposition** | En conditionnement : catégorie d'unités hors circuit vendable (casse, QA, taproom…). |
| **Avoir** | Document comptable émis quand un client retourne de la marchandise. |
| **Blanc de livraison (BL)** | Bon de livraison accompagnant une expédition. |
| **Site** | Lieu de stockage ou de production identifié dans l'application (Le Zeppelin, Zgeg, etc.). |
| **Clôture mensuelle** | Comptage de fin de mois servant de base aux calculs comptables. |
| **Giveaway** | Produit offert sans vente formelle (cadeau, dégustation, presse). |
| **Quarantaine** | Zone de produits en attente d'analyse ou de décision (ni vendables, ni éliminés). |
| **Hors process** | Mode réservé aux managers : déverrouille des opérations normalement bloquées. |
| **B2B** | Vente entre professionnels (bars, restaurants, distributeurs). |
| **E-shop / Boutique en ligne** | Site de vente en ligne de La Nébuleuse (via Shopify). |
| **Taproom** | Espace de vente et dégustation directe de La Nébuleuse. |
| **BC** | Business Central : système comptable et ERP de référence pour les commandes et la facturation. |
| **Matières premières (MP)** | Ingrédients et consommables de production (malts, houblons, adjuvants, emballages). |

---

## Annexe E — Dispositions de conditionnement

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
| Bouteilles remplies mais avec mousse excessive, bouchées sans carbonatation correcte | Invendable |
| Bouteilles remplies mais la capsuleuse tombe en panne avant capsulage | Perte liquide sans capsule |
| Bouteilles remplies à mi-course d'un run interrompu | Perte liquide à moitié remplie |
| 2 bouteilles mises de côté pour analyse au labo | Mesures QA |
| 6 bouteilles archivées pour la bibliothèque du brasseur | Bibliothèque QA |
| Fût branché directement au taproom depuis la salle de cuve | Fût taproom |
| 10 capuchons de fûts perdus pendant la pose | Perte capuchon fût |

---

## Annexe F — Listes de contrôle (checklists)

Ces checklists sont des aide-mémoire rapides. Elles ne remplacent pas les procédures détaillées du manuel.

---

### Checklist quotidienne — Opérateur logistique

#### Matin

- [ ] Consulter la vue **Commandes** : quelles commandes sont à préparer aujourd'hui ?
- [ ] Consulter la vue **Boutique en ligne** : y a-t-il des commandes e-shop **Nouveau** ou **En préparation** ?
- [ ] Vérifier la vue **Stock PF par site** : y a-t-il des alertes **Critique** ou **Survendu** ?
- [ ] Signaler toute alerte Critique ou Survendu au responsable de production.

#### En cours de journée

- [ ] Avancer le statut des commandes au fur et à mesure (Confirmée → Préparée → BL imprimé → Livrée).
- [ ] Saisir les transferts inter-sites dès qu'un déplacement de stock est effectué.
- [ ] Saisir les runs de conditionnement dans les deux heures suivant la fin de la session.

#### Soir

- [ ] Vérifier que tous les runs de la journée ont bien été saisis en Conditionnement.
- [ ] Vérifier que toutes les expéditions du jour ont leur statut à jour (Livrée ou BL imprimé).
- [ ] Saisir les transferts inter-sites effectués en fin de journée si ce n'est pas encore fait.

---

### Checklist hebdomadaire — Comptage Stock PF

À réaliser en fin de semaine (vendredi soir ou samedi matin) pour chaque site actif.

**Avant de commencer :**
- [ ] Choisir un moment calme (pas en pleine expédition).
- [ ] Avoir accès à toutes les zones de stockage.
- [ ] Préparer papier + stylo pour noter les quantités par zone avant saisie.

**Pendant le comptage :**
- [ ] Zone 1 — référence par référence, quantité exacte notée sur papier.
- [ ] Zone 2 — idem.
- [ ] Zone 3 — idem (adapter selon le plan de l'entrepôt).
- [ ] Vérifier les cartons entamés : compter les unités, pas les cartons.
- [ ] Pour chaque référence absente de toutes les zones : noter 0 (pas laisser vide).

**Saisie dans l'application :**
- [ ] Ouvrir Expéditions → Comptage Stock PF.
- [ ] Sélectionner le bon site.
- [ ] Vérifier la date (date du comptage, pas date de saisie si différentes).
- [ ] Cocher "Clôture mensuelle" uniquement si c'est la fin du mois comptable.
- [ ] Saisir ligne par ligne depuis les notes papier.
- [ ] Toute référence à zéro → saisir 0, jamais laisser vide.
- [ ] Soumettre.
- [ ] Vérifier le message de confirmation.

**Après soumission :**
- [ ] Consulter le Stock PF par site pour vérifier que les chiffres sont cohérents avec le comptage.
- [ ] Signaler tout écart inexpliqué > 5% à votre responsable.

---

### Checklist mensuelle — Comptage Matières Premières

À réaliser en fin de mois, idéalement sur 1-2 jours.

**Avant de commencer :**
- [ ] Vérifier que le comptage du mois précédent est archivé (votre responsable peut le confirmer).
- [ ] Avoir accès à toutes les zones de stockage MP (magasin malts, chambre froide houblons, local emballages…).
- [ ] Identifier les ingrédients récemment reçus qui ne seraient pas encore dans la liste (signaler au responsable).

**Pendant le comptage :**
- [ ] Malts : compter sac par sac, noter le poids (kg) et le N° de lot si visible.
- [ ] Houblons : compter par contenant/variété, noter en kg ou g.
- [ ] Adjuvants et minéraux : noter en kg, g ou L selon l'unité habituelle.
- [ ] Emballages : noter en nombre d'unités (capsules, étiquettes, cartons) ou en poids si applicable.
- [ ] Produits de nettoyage : noter en L ou kg.

**Saisie dans l'application :**
- [ ] Ouvrir Inventaire MP.
- [ ] Vérifier que la période est bien le mois en cours.
- [ ] Pour chaque ingrédient compté : chercher → saisir → Ajouter.
- [ ] Pour les ingrédients épuisés : chercher → saisir 0 → Ajouter.
- [ ] Fermer l'application une fois terminé (la sauvegarde est automatique ligne par ligne).

**Après saisie :**
- [ ] Signaler à votre responsable si un ingrédient attendu n'est pas dans la liste.
- [ ] Signaler tout stock négatif ou suspect (ex. stock présent mais non consommé depuis des mois).

---

### Checklist — Saisie d'un run de conditionnement

À compléter pour chaque session de mise en emballage.

**Avant de commencer la session physique :**
- [ ] Confirmer le lot source (bière en BBT/CCT) avec le brasseur responsable.
- [ ] Prendre les mesures CO₂ et O₂ avant le premier remplissage.
- [ ] Identifier le(s) format(s) à conditionner ce jour.
- [ ] Si white label : confirmer le nom du client avec le responsable.

**Pendant la session :**
- [ ] Compter les unités au fur et à mesure (ne pas se fier à la mémoire en fin de run).
- [ ] Identifier et compter les dispositions (casse, QA, invendables…) au fil de la session.

**Saisie dans l'application :**
- [ ] Ouvrir Conditionnement.
- [ ] Sélectionner le bon lot source.
- [ ] Sélectionner le bon type de run.
- [ ] Sélectionner le bon format.
- [ ] Saisir CO₂ et O₂ (si pas déjà repris automatiquement).
- [ ] Saisir la quantité principale.
- [ ] Si formats parallèles : ajouter chaque ligne parallèle avec sa propre quantité.
- [ ] Compléter les dispositions si nécessaire.
- [ ] Relire le récapitulatif avant soumission.
- [ ] Soumettre.
- [ ] Vérifier que le Stock PF a bien augmenté de la quantité produite (vue Stock PF par site).

---

### Checklist — Expédition d'une commande B2B

**Réception de la commande :**
- [ ] Ouvrir la commande dans la vue Commandes.
- [ ] Vérifier le client, la date de livraison, le site d'expédition.
- [ ] Vérifier les lignes (références et quantités).
- [ ] Signaler toute anomalie avant de commencer la préparation.

**Préparation du colis :**
- [ ] Préparer le stock physiquement (picking dans les zones de stockage).
- [ ] Vérifier chaque référence : bonne bière, bon format, bonne quantité.
- [ ] Passer le statut à **Préparée** dans l'application.

**Expédition :**
- [ ] Imprimer le bon de livraison → passer à **BL imprimé**.
- [ ] Remettre le colis au transporteur.
- [ ] Passer à **Livrée** dans l'application.

**En cas de problème pendant la préparation :**
- [ ] Stock insuffisant → ne pas expédier partiellement sans accord du manager.
- [ ] Référence manquante → signaler au manager avant de substituer.
- [ ] Dommage constaté sur le stock → isoler le lot endommagé et signaler.

---

### Checklist — Saisie d'un retour physique

**Réception physique :**
- [ ] Vérifier visuellement la marchandise retournée.
- [ ] Séparer par état : intact / endommagé / douteux.
- [ ] Demander le numéro d'avoir à la comptabilité si non communiqué.

**Dans l'application :**
- [ ] Ouvrir Expéditions → Retours & avoirs.
- [ ] Saisir le numéro d'avoir.
- [ ] Vérifier que les lignes affichées correspondent à ce que vous avez reçu.
- [ ] Choisir la disposition pour chaque ligne (Remise en stock / Casse / Quarantaine).
- [ ] Soumettre.

**Après soumission :**
- [ ] Produits en Remise en stock → les ranger dans la zone de stockage normale.
- [ ] Produits en Quarantaine → les mettre en zone de quarantaine avec étiquette de référence et date.
- [ ] Produits en Casse → éliminer selon la procédure habituelle (recyclage, destruction).
- [ ] Informer votre responsable du retour et de son état.

---

## Annexe G — Scénarios courants et conduite à tenir

Cette annexe décrit les situations inhabituelles les plus fréquentes et la marche à suivre recommandée. Elle complète les procédures standard.

---

### Scénario 1 : Le lot n'apparaît pas dans la liste Transferts

**Symptôme :** vous cherchez un lot pour un transfert mais il n'apparaît pas dans la liste des lots éligibles.

**Causes possibles et vérifications :**

1. **Le Cold Crash n'est pas enregistré.**
   - Aller dans Fermentation → trouver le lot → vérifier si "Cold Crash" est coché.
   - Si non coché : le cocher maintenant (avec la date réelle du Cold Crash) et soumettre.
   - Revenir dans Transferts : le lot devrait maintenant apparaître.

2. **La durée de garde minimale n'est pas atteinte.**
   - Le brasseur responsable peut confirmer si le lot est prêt.
   - Si une exception est justifiée → demander à un manager d'activer le mode Hors process.

3. **Le brassage n'a pas été saisi.**
   - Si le lot n'existe pas encore dans le système (aucune trace en Fermentation non plus) → le brassage doit être saisi en premier.

4. **Le lot est dans une cuve mais la cuve n'est pas renseignée.**
   - Un manager peut corriger l'affectation de cuve directement.

**À ne pas faire :** ne pas forcer le transfert en créant une saisie manuelle hors procédure. Contacter votre responsable.

---

### Scénario 2 : Un run de conditionnement a été oublié

**Symptôme :** le Stock PF est plus bas qu'attendu et vous réalisez qu'un run n'a pas été saisi.

**Conduite à tenir :**

1. Retrouvez vos notes ou documents physiques du run (papier de comptage, etc.).
2. Ouvrez le formulaire Conditionnement.
3. Saisissez le run avec la **date réelle de la session** (pas la date du jour si c'est différent).
4. Vérifiez que la quantité saisie correspond bien à ce qui a été produit ce jour-là.
5. Soumettez.
6. Consultez le Stock PF : vérifiez que la correction est visible.
7. Signalez à votre responsable qu'un run avait été omis et qu'il a été rattrapé.

> Si vous ne retrouvez pas les données du run (quantité exacte, format, dispositions), prévenez votre responsable plutôt que de saisir une estimation.

---

### Scénario 3 : Une commande e-shop contient une référence non disponible en stock

**Symptôme :** vous préparez une commande e-shop mais la référence demandée est absente du stock ou en quantité insuffisante.

**Conduite à tenir :**

1. Vérifiez le Stock PF par site pour confirmer l'absence ou l'insuffisance.
2. Vérifiez si du stock est en transit (transfert inter-sites en attente d'arrivée).
3. Vérifiez si un run de conditionnement récent couvrirait la référence mais n'a pas encore été saisi.
4. Si le stock est réellement insuffisant → signalez à votre responsable **sans passer la commande en Expédié**.
5. Votre responsable arbitre : délai au client, substitution, annulation partielle.

> Ne modifiez jamais la commande (référence ou quantité) sans accord d'un manager. Ne substituez pas une référence par une autre sans accord explicite.

---

### Scénario 4 : Un transfert inter-sites a été saisi avec la mauvaise direction

**Symptôme :** vous avez saisi un transfert de site A vers site B alors que c'était B vers A (ou vous avez échangé les sites).

**Conduite à tenir :**

1. Ne resoumettez pas un transfert inverse de votre propre initiative.
2. Contactez un administrateur immédiatement.
3. Précisez : la date du transfert erroné, les sites concernés, les références et quantités.
4. L'administrateur annule le transfert erroné et vous guide pour saisir le bon.

> Une annulation de transfert erronée et une resaisie non coordonnée peuvent créer des doublons ou des doubles annulations qui faussent encore plus le stock.

---

### Scénario 5 : Un client conteste les quantités d'une livraison déjà marquée Livrée

**Symptôme :** une commande est en statut Livrée dans le système mais le client affirme avoir reçu moins (ou plus) que commandé.

**Conduite à tenir :**

1. Ne modifiez pas le statut ou les quantités dans l'application.
2. Signalez à votre responsable avec les informations : numéro de commande, client, quantité système, quantité contestée par le client.
3. Votre responsable coordonne avec la comptabilité pour la suite (avoir, retour, ajustement).
4. Si un retour physique s'ensuit → appliquer la procédure Retours & avoirs (Annexe F checklist + section 5.1).

---

### Scénario 6 : L'application est inaccessible (panne réseau ou serveur)

**Symptôme :** `app.maltytask.ch` ne s'ouvre plus, ou affiche une erreur de connexion.

**Conduite à tenir :**

1. **Vérifiez d'abord le Wi-Fi** de votre tablette : êtes-vous bien connecté ?
2. Essayez de rafraîchir la page (balayez vers le bas, ou appuyez sur le bouton de rechargement).
3. Essayez depuis un autre appareil ou navigateur pour confirmer que c'est le serveur et non votre tablette.
4. **En attendant le retour de l'application :**
   - Notez tout sur papier : runs de conditionnement, transferts, quantités comptées.
   - Ne prenez aucune décision d'expédition si vous ne pouvez pas vérifier le stock.
5. Signalez à votre responsable ou à un administrateur système.
6. À la reprise de l'application : saisissez les événements en retard avec leurs dates réelles.

---

### Scénario 7 : Deux opérateurs ont compté le même site le même jour

**Symptôme :** deux comptages ont été soumis pour le même site à la même date (peut arriver si deux opérateurs travaillent en parallèle sans coordination).

**Conduite à tenir :**

1. Ne soumettez pas un troisième comptage pour "corriger".
2. Contactez votre responsable avec les détails : qui a compté, quelle zone, à quelle heure.
3. Le manager ou l'administrateur peut arbitrer quel comptage est le bon ou les fusionner.

> Pour éviter cette situation : coordonnez-vous avant de commencer un comptage. Désignez un opérateur responsable du comptage par site chaque semaine.

---

### Scénario 8 : Un ingrédient n'est pas trouvable dans la liste de l'Inventaire MP

**Symptôme :** vous cherchez un ingrédient dans le formulaire Inventaire MP mais il n'apparaît pas, même en tapant plusieurs lettres.

**Conduite à tenir :**

1. Essayez différentes graphies (accent / sans accent, abréviation vs nom complet, langue allemande vs française pour certains ingrédients).
2. Si vous ne trouvez toujours pas → **ne saisissez pas un ingrédient "proche"** comme substitut.
3. Signalez à votre responsable : l'ingrédient peut ne pas être encore référencé dans le système.
4. Un manager ou administrateur peut créer la référence manquante.
5. Revenez saisir le comptage une fois la référence créée.

---

### Scénario 9 : La vue Boutique en ligne n'affiche pas une commande attendue

**Symptôme :** un client signale avoir passé une commande en ligne mais elle n'apparaît pas dans la vue Boutique en ligne.

**Conduite à tenir :**

1. Attendez 5-10 minutes et rafraîchissez : la synchronisation peut avoir un léger délai.
2. Vérifiez si la commande est dans un statut filtré (certains filtres masquent les commandes annulées ou très anciennes).
3. Demandez au client de vous communiquer son numéro de commande ou l'adresse e-mail de confirmation.
4. Si la commande n'apparaît toujours pas après 30 minutes → signalez à votre responsable avec le numéro de commande Shopify du client.

> Ne créez pas de commande manuelle pour remplacer une commande e-shop qui "aurait dû arriver". Cela créerait un doublon quand la commande e-shop s'afficherait finalement.

---

### Scénario 10 : Le stock affiché semble beaucoup trop élevé pour une référence e-shop

**Symptôme :** une référence très active en boutique en ligne affiche un stock qui ne descend pas malgré de nombreuses ventes.

**Conduite à tenir :**

1. Vérifiez la date du dernier comptage pour ce site.
2. Vérifiez si des runs de conditionnement récents auraient gonflé le stock.
3. Si le stock semble surévalué malgré un comptage récent → c'est probablement la **limitation e-shop connue** (certaines ventes e-shop ne décrémantent pas encore le stock en temps réel).
4. Signalez à votre responsable en indiquant : la référence concernée, le stock affiché, le stock estimé réel, la période concernée.
5. **Ne corrigez pas en comptant un stock artificiellement bas** — cela masquerait le problème et empêcherait sa correction technique.

> Le comptage hebdomadaire reste le meilleur correctif temporaire : il réancre le stock sur la réalité physique une fois par semaine.

---

## Annexe H — Utilisation de l'application sur tablette : conseils pratiques

### Configuration recommandée

Pour une utilisation optimale en atelier ou entrepôt :

- **Orientation :** paysage (horizontale) pour les tableaux, portrait (verticale) pour les formulaires.
- **Luminosité :** augmentez la luminosité au maximum si vous êtes en extérieur ou dans une zone bien éclairée.
- **Zoom :** si le texte est trop petit, utilisez le pincement pour zoomer sur les sections importantes. L'application s'adapte.
- **Clavier :** le clavier virtuel peut masquer une partie du formulaire. Faites défiler vers le haut si vous ne voyez plus un champ.

### Gestes utiles

| Geste | Action |
|---|---|
| **Balayer vers le bas** | Rafraîchir la page |
| **Pincer / écarter** | Zoomer / dézoomer |
| **Appui long** | Sur certains éléments, affiche un menu contextuel |
| **Glisser depuis le bord** | Retour en arrière (navigateur) |

### Éviter les soumissions accidentelles

- Ne tapez pas plusieurs fois sur le bouton Soumettre si la page tarde à répondre. Une seule pression suffit — le système traite la demande.
- Si la page semble bloquée après une soumission, attendez 10-15 secondes avant de rafraîchir. Rafraîchir trop vite peut créer un doublon.
- En cas de doute sur si la soumission a abouti → consultez la section concernée (ex. Stock PF pour un conditionnement) plutôt que de resoummettre.

### Session et déconnexion

- La session reste active tant que vous n'êtes pas déconnecté. Sur une tablette partagée, **déconnectez-vous à la fin de votre poste**.
- Si la tablette est perdue ou volée, signalez immédiatement à un administrateur pour désactiver la session.

### Tablette partagée entre opérateurs

Si plusieurs opérateurs utilisent la même tablette :
- Chaque opérateur doit se connecter avec son propre compte.
- Déconnectez-vous avant de remettre la tablette à un collègue.
- Ne partagez jamais votre mot de passe. Si un collègue n'a pas de compte, il doit en demander un à un administrateur.

### En cas de blocage de l'écran

Si l'écran de la tablette se bloque pendant une saisie en cours et que les données saisies semblent perdues :
1. Retournez dans le formulaire (navigation → menu → page concernée).
2. Vérifiez si les données sont encore présentes (certains formulaires conservent une saisie en cours).
3. Si les données sont perdues → ressaisissez depuis le début avec les notes papier.
4. Conseil préventif : sur les longues saisies (comptage complet, gros brassage), notez les données sur papier en parallèle.

---

## Annexe I — Contacts et responsabilités

### Qui contacter pour quoi

| Situation | Interlocuteur |
|---|---|
| Problème technique (application inaccessible, erreur système) | Administrateur système |
| Erreur de saisie déjà soumise (conditionnement, transfert, retour) | Manager ou administrateur |
| Annulation d'un transfert inter-sites | Administrateur uniquement |
| Écart de stock important constaté lors d'un comptage | Responsable logistique |
| Lot bloqué ou absent dans les formulaires | Responsable brassage |
| Numéro d'avoir pour un retour client | Service comptabilité |
| Commande B2B absente ou erronée | Responsable logistique + comptabilité |
| Commande e-shop non reçue dans l'application | Responsable logistique ou administrateur |
| Ajout d'un nouvel ingrédient dans l'Inventaire MP | Responsable brassage ou administrateur |
| Création d'un compte pour un nouvel opérateur | Administrateur |
| Réinitialisation de mot de passe | Administrateur |

### Ce que vous pouvez faire sans demander d'autorisation

- Lire et consulter toutes les vues (stock, commandes, historique, etc.).
- Soumettre des formulaires de production (brassage, fermentation, conditionnement, transferts de cuve).
- Avancer les statuts des commandes dans l'ordre normal.
- Saisir un comptage Stock PF (à la date du jour).
- Saisir un comptage Matières Premières.
- Saisir un transfert inter-sites.
- Créer une commande interne.
- Ajouter un élément au stock d'accompagnement.

### Ce qui nécessite l'accord d'un manager

- Antidater un comptage.
- Cocher "Clôture mensuelle" sur un comptage.
- Activer le mode Hors process dans la page Transferts.
- Modifier une commande déjà soumise.
- Substituer une référence dans une commande.
- Modifier ou supprimer un élément du stock d'accompagnement.
- Créer un retour sans numéro d'avoir.

### Ce qui nécessite l'accord d'un administrateur

- Annuler un transfert inter-sites.
- Corriger un run de conditionnement déjà soumis.
- Créer ou modifier un compte utilisateur.
- Créer un client ou modifier les données d'un client.
- Modifier un brassage déjà soumis (données clés : volume, densité).
- Accéder aux paramètres système ou à la configuration.

---

*Manuel opérateur — Logistique & Entrepôt — La Nébuleuse — juin 2026.*
*Pour toute question ou suggestion d'amélioration, contacter votre responsable.*
*Ce document est interne à La Nébuleuse. Ne pas diffuser en dehors de l'entreprise.*
