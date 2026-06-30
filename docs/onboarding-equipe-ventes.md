# Guide de démarrage — maltytask (équipe Ventes)

Bienvenue ! Ce guide vous accompagne pas à pas pour accéder à **maltytask**, l'outil
de gestion de La Nébuleuse, et vous présente les deux pages que vous utiliserez au
quotidien : **Mon tableau** et **Expéditions**.

Comptez environ **10 minutes** pour la première installation. Vous ne le faites
qu'une seule fois.

> **En un coup d'œil** — 3 étapes :
> 1. Installer **Tailscale** (le « tunnel » sécurisé qui ouvre l'accès à l'outil)
> 2. Créer votre **mot de passe** via le lien reçu par e-mail
> 3. Se connecter sur **https://app.maltytask.ch**
>
> ⚠️ **L'ordre compte : installez Tailscale AVANT de cliquer sur le lien du mot de
> passe.** Sans Tailscale actif, la page affichera une erreur (403).

---

## Étape 1 — Installer Tailscale

maltytask n'est **pas accessible sur l'internet public** : pour des raisons de
sécurité, l'outil n'est joignable qu'à travers un réseau privé sécurisé appelé
**Tailscale**. C'est gratuit, léger, et une fois installé vous l'oubliez : il tourne
en arrière-plan.

Vous recevrez d'abord une **invitation à rejoindre le réseau Tailscale de La
Nébuleuse** (gérée par Kouros). Vous vous connecterez avec votre **compte Google
professionnel `prénom@lanebuleuse.ch`**.

### 📱 Sur téléphone

**iPhone / iPad :**
1. Ouvrez l'**App Store**, cherchez **« Tailscale »**, installez l'application.
2. Ouvrez l'app, touchez **« Sign in »** (Se connecter).
3. Choisissez **« Sign in with Google »** et connectez-vous avec votre adresse
   **`@lanebuleuse.ch`**.
4. Acceptez l'invitation au réseau si elle s'affiche.
5. Activez l'interrupteur en haut : il doit indiquer **« Connected »** (Connecté).

**Android :**
1. Ouvrez le **Play Store**, cherchez **« Tailscale »**, installez l'application.
2. Ouvrez l'app, touchez **« Sign in »**, puis **« Sign in with Google »** avec votre
   adresse **`@lanebuleuse.ch`**.
3. Activez Tailscale (interrupteur sur **Connected**).
4. ⚠️ **Important sur Android** — si la page de l'outil ne se charge pas : allez dans
   **Réglages → Réseau & Internet → DNS privé (Private DNS)** et mettez-le sur
   **« Automatique »** ou **« Désactivé »**. Un DNS privé personnalisé empêche
   Tailscale de fonctionner correctement.

### 💻 Sur ordinateur

**Windows / Mac :**
1. Allez sur **https://tailscale.com/download**.
2. Téléchargez la version pour votre système (Windows ou macOS) et installez-la
   (double-clic, suivez les étapes).
3. Une icône Tailscale apparaît dans la barre des tâches (Windows, en bas à droite)
   ou la barre de menus (Mac, en haut à droite).
4. Cliquez l'icône → **« Sign in »** → **« Sign in with Google »** avec votre adresse
   **`@lanebuleuse.ch`**.
5. Vérifiez que Tailscale est **« Connected »** (l'icône ne doit pas être barrée).

> 💡 Vous pouvez installer Tailscale **à la fois** sur votre téléphone et votre
> ordinateur avec le même compte Google — c'est même recommandé.

---

## Étape 2 — Créer votre mot de passe (premier accès)

Une fois votre compte créé par l'administrateur, vous recevez un **e-mail de
bienvenue** à votre adresse `@lanebuleuse.ch` contenant un **lien personnel** pour
définir votre mot de passe.

1. ✅ **Vérifiez d'abord que Tailscale est installé et activé** (Connected). Sans
   ça, le lien affichera une erreur.
2. Ouvrez l'e-mail de bienvenue et cliquez sur le lien **« Définir mon mot de
   passe »**.
3. Choisissez votre mot de passe, validez.
4. Vous êtes automatiquement connecté(e). Une **Visite guidée** vous présente
   l'interface — laissez-vous guider, ça prend une minute.
5. Vous arrivez sur **Mon tableau** : vous êtes prêt(e) !

> ⏳ **Le lien est valable 72 heures.** S'il a expiré, ou si vous n'avez pas reçu
> l'e-mail, demandez à Kouros de vous renvoyer une invitation.
>
> 🔑 **Il n'y a pas de lien « mot de passe oublié »** sur la page de connexion. Si
> vous êtes bloqué(e), contactez l'administrateur (Kouros) qui vous renverra un
> lien.

---

## Étape 3 — Se connecter au quotidien

1. Assurez-vous que **Tailscale est activé** (Connected).
2. Ouvrez votre navigateur sur **https://app.maltytask.ch**
   *(ajoutez la page à vos favoris / écran d'accueil).*
3. Saisissez votre **identifiant** — c'est votre **Prénom Nom** (ou votre adresse
   e-mail) — et votre mot de passe.

C'est tout. Vous retrouvez **Mon tableau** comme page d'accueil.

---

## La page « Mon tableau »

C'est votre **tableau de bord personnel** et votre page d'accueil. Vous y composez
vous-même les indicateurs (KPI) que vous voulez suivre.

- **Choisir vos indicateurs** — un sélecteur vous propose les indicateurs
  disponibles pour vous. En tant que membre de l'équipe Ventes, vous avez accès aux
  indicateurs commerciaux, notamment :
  - **Unités vendues par référence (SKU)** — combien de chaque produit a été vendu.
  - **Top SKU par volume et chiffre d'affaires** — vos meilleures références.
- **Organiser** — sélectionnez/désélectionnez les cartes ; votre choix est
  mémorisé, vous le retrouvez à chaque connexion.
- **Recevoir des récapitulatifs par e-mail** — vous pouvez vous abonner à un envoi
  **quotidien, hebdomadaire ou mensuel** des indicateurs de votre choix.
- **Fraîcheur des données** — une tuile vous signale si un inventaire est en retard,
  pour que vous sachiez si les chiffres affichés sont à jour.

> 💡 Si votre tableau semble vide au premier abord, c'est normal : ouvrez le
> sélecteur d'indicateurs et ajoutez les cartes Ventes qui vous intéressent.

---

## La page « Expéditions »

C'est le cœur du suivi logistique B2B : l'état des **commandes** et le **stock de
produits finis**. Vous y accédez **en lecture seule** (voir « Votre périmètre »
ci-dessous) — vous pouvez **tout consulter et filtrer**, mais pas modifier.

En haut de la page, une **barre d'onglets** donne accès aux différentes vues. Les
deux qui vous concernent en priorité :

### Onglet « Commandes » (suivi B2B)

Le tableau de bord des commandes B2B : chaque commande, son client, et son
**état d'avancement** (préparée, expédiée, etc.).

- **Filtres** — filtrez par **client**, par vue, ou utilisez la **recherche** pour
  retrouver une commande. Tous les filtres fonctionnent librement en lecture seule.
- Une **pastille de fraîcheur** vous indique l'actualité des données.

### Onglet « Stock PF » (stock de produits finis)

L'état **en temps réel du stock de produits finis**, par référence (SKU) et par
site.

- **Couverture / « semaines de stock »** — pour chaque produit, une estimation du
  nombre de **semaines de stock** restantes, calculée à partir du **rythme de vente
  réel et des commandes en cours** (moteur prévisionnel). Un indicateur de santé
  vous signale les produits en tension.
- **Export CSV** — un bouton **⬇ CSV** vous permet d'exporter à l'écran exactement
  ce que vous voyez, pour le retravailler dans Excel.

> Les autres onglets (E-shop, Historique, Carnet clients, Mouvements, Retours…)
> restent **visibles** : vous pouvez les consulter, mais les boutons de saisie/
> modification y sont **désactivés** pour vous.

---

## Votre périmètre d'accès (lecture seule)

Pour information, votre compte (équipe Ventes) est configuré ainsi :

| Vous pouvez | Vous ne pouvez pas |
|---|---|
| Consulter **Mon tableau** et vos indicateurs Ventes | Modifier des commandes, des stocks ou des saisies |
| Consulter **Expéditions** : Commandes B2B + Stock PF | Accéder aux pages Production, Finances, ou Administration |
| **Filtrer, rechercher, exporter** (CSV) librement | Créer ou supprimer des données |

C'est un accès **consultation** : vous ne risquez jamais de casser ou modifier
quelque chose par mégarde.

---

## Besoin d'aide ?

- **Je n'arrive pas à ouvrir la page (erreur / page blanche)** → vérifiez que
  **Tailscale est activé** (Connected). Sur Android, vérifiez le **DNS privé**
  (Étape 1).
- **Lien de mot de passe expiré / pas reçu / mot de passe oublié** → contactez
  **Kouros** pour un nouveau lien.
- **Une question sur un chiffre ou une fonctionnalité** → adressez-vous à votre
  responsable des ventes (Louis) ou à Kouros.

Bon démarrage ! 🍺
