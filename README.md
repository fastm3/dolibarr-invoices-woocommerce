# Factures Dolibarr pour WooCommerce

Plugin WordPress qui affiche, dans le **compte client WooCommerce**, les **factures Dolibarr** liées aux commandes :

- une colonne **« Facture »** dans la liste des commandes de « Mon compte » ;
- un bouton **« Voir la facture »** sur la page de chaque commande ;
- le PDF passe **par votre site** (mode proxy) : **Dolibarr reste invisible pour le client** (ni URL, ni clé API exposées) ;
- dans l'**admin WooCommerce**, un encart sur chaque commande : « Facture Dolibarr liée » avec boutons *Voir la facture (PDF via le site)* et *Ouvrir dans Dolibarr* ;
- dans l'**admin WooCommerce**, un bouton **« Créer la commande brouillon Dolibarr »** : crée dans Dolibarr une commande client au statut brouillon avec les lignes de la commande (produits reliés via un extrafield).

---

## Architecture

```
Client (Mon compte WooCommerce)
        │  (1) le plugin cherche la facture de la commande
        ▼
Plugin WordPress  ──API REST──▶  Dolibarr
   (colonne + bouton)            (factures liées aux commandes)
        │  (2) affichage
        ▼
PDF récupéré côté serveur (clé API) puis affiché via votre site
   → le client ne voit jamais Dolibarr
```

Le plugin ne crée **pas** les factures : il suppose qu'elles existent déjà dans Dolibarr (créées manuellement ou par un connecteur). Depuis la **v1.10.0**, il peut en revanche créer une **commande client brouillon** dans Dolibarr (voir plus bas) — jamais une facture.

---

## Prérequis

| Élément | Détail |
|---|---|
| WordPress | ≥ 6.0 |
| WooCommerce | ≥ 6.0 (compatible HPOS) |
| Dolibarr | ≥ 14 (API REST stable), recommandé ≥ 16 |
| Module Dolibarr « Web services REST » | Activé |
| Clé API Dolibarr | Avec au minimum le droit **lecture des factures** |
| Module Dolibarr « External access » | **Non requis** en mode proxy (recommandé) |

---

## Étape 1 — Côté Dolibarr

### 1. Activer l'API REST

1. **Setup → Modules/Applications** → chercher **« Web services »** (REST) → activer.
2. Menu **« API »** (en haut à droite de l'interface Dolibarr) → **Créer une clé** :
   - choisir l'utilisateur qui « porte » la clé (créez un utilisateur dédié, ex. `api-woo`) ;
   - donner les droits **lecture** sur le module **Factures** (et éventuellement Tiers) ;
   - copier la clé générée.

### 2. Renseigner le lien commande → facture (facturation manuelle)

À chaque facture créée à la main dans Dolibarr :

1. Créez la facture pour le client (votre workflow habituel).
2. Dans le champ **« Réf. client »** de la facture, saisissez le **numéro de commande WooCommerce** tel qu'il apparaît dans « Mon compte → Commandes » (ex. `1345` ou `WC-1345`).
3. **Validez** la facture (statut *Validée* ou *Payée* — les brouillons et factures annulées sont ignorés).

Le plugin trouve alors la facture automatiquement. **Correspondance exacte** obligatoire (pas d'espace, même casse), et **une facture par commande** (la plus récente est affichée en cas de doublon).

> Alternative : mode « ID manuel » du plugin — saisir l'ID de la facture Dolibarr (visible dans l'URL `…/facture/card.php?facid=ID`) sur la commande dans l'admin WooCommerce.

> Si vous préférez l'automatisation complète : installez le connecteur officiel (module **« WooCommerce connector »** sur [Dolistore](https://www.dolistore.com/product.php?id=489&title=woocommerce-integration&languages=en)) qui crée les factures dès que la commande est payée. Vérifiez alors que `ref_client` = numéro de commande (ou passez en mode ID manuel).

---

## Étape 2 — Côté WordPress

1. Copiez le dossier `dolibarr-invoices-woocommerce` dans `wp-content/plugins/` (ou installez le `.zip` via *Extensions → Ajouter → Téléverser*).
2. Activez le plugin.
3. Allez dans **WooCommerce → Factures Dolibarr** :
   - **URL de Dolibarr** : `https://dolibarr.exemple.fr` (sans slash final) ;
   - **Clé API** : la clé créée à l'étape 1 ;
   - **Chemin de l'interface Dolibarr** : à renseigner uniquement si l'interface Web de Dolibarr est dans un sous-dossier différent de l'API (ex. API sur `https://…/`, fiches sur `https://…/compta/facture/card.php` → saisir `/compta`). Sert au lien « Ouvrir dans Dolibarr » ;
   - **Méthode de correspondance** :
     - `Réf. client` : automatique, si `ref_client` = numéro de commande (recommandé) ;
     - `ID manuel` : vous saisissez l'ID de la facture sur chaque commande.
   - **Mode d'affichage** : **`Proxy` (recommandé)** — voir ci-dessous.
   - **Durée du cache** : temps de mémorisation de la correspondance (défaut 1 h). Baissez-le (ex. 5 min) si vous facturez manuellement après coup.
4. Cliquez sur **« Tester la connexion à Dolibarr »** : le message doit confirmer que l'API répond.
5. (Diagnostic) Cliquez sur **« Tester le téléchargement d'un PDF »** : le plugin prend la dernière facture, vérifie ses pièces jointes et télécharge le PDF. Le message indique soit *« PDF récupéré : facture …, N octets, type "application/pdf" »* (tout fonctionne), soit l'étape exacte en échec.

### Faire passer les factures par le site — Dolibarr invisible

1. Réglez **Mode d'affichage** sur **`Proxy : téléchargement via l'API`** (valeur par défaut du plugin).
2. Le bouton client « Voir la facture » pointe alors vers `admin-post.php` **de votre site** : le plugin récupère le PDF côté serveur (avec la clé API) et l'affiche au client.
3. Le client ne voit **jamais** l'URL de Dolibarr, ni la clé API — même en cas d'erreur (message générique « facture temporairement indisponible »).

⚠️ Les modes `Auto` et `Lien public` **ouvrent une page de votre Dolibarr chez le client** (lien sécurisé `hashp`). Ne les utilisez pas si Dolibarr doit rester invisible.

### Vérification côté admin

Sur la page de modification d'une commande (WooCommerce → Commandes → modifier), le plugin affiche :

- **Facture trouvée** → encart **« Facture Dolibarr liée »** avec référence, date, montant et deux boutons :
  - **Voir la facture (PDF via le site)** — même proxy que côté client ;
  - **Ouvrir dans Dolibarr** — fiche facture dans votre Dolibarr (réservé à l'admin, nécessite d'être connecté à Dolibarr dans le même navigateur) ;
  - **Compte client Dolibarr → « Ouvrir la fiche client »** — lien automatique vers la fiche du tiers (retrouvé via la facture liée, ou par l'e-mail du client si aucune facture n'est encore liée ; si rien n'est trouvé, un message explique les causes probables).
- **Sans commande** : la recherche par e-mail est aussi disponible dans **Utilisateurs** (colonne « Compte Dolibarr » avec lien « Fiche client ») et sur la **fiche utilisateur** (encart « Compte client Dolibarr »). Réservé à l'admin. NB : la fiche tiers est ouverte à la racine (`/societe/card.php`), indépendamment du sous-dossier d'interface des factures.
- **Aucune facture trouvée** → encart de **diagnostic** (fond orangé) expliquant les causes possibles (statut de la facture, « Réf. client » ≠ numéro de commande, API non configurée) + bouton **« Actualiser la recherche »** qui vide le cache de la commande (utile si la facture a été créée après une première consultation).

Le champ « ID facture Dolibarr » ne s'affiche qu'en mode de correspondance **« ID manuel »** (sinon, une ligne rappelle le numéro de commande utilisé pour la recherche automatique).

### Envoi de la facture par e-mail

Deux mécanismes (depuis la **v1.9.0**) :

- **Automatique** : quand une commande passe au statut **Terminée**, la facture Dolibarr liée est **jointe à l'e-mail « Commande terminée »** envoyé au client (si la facture est liée à ce moment-là — sinon l'e-mail part sans pièce jointe, sans erreur).
- **Bouton** : sur la fiche commande (admin), **« Envoyer la facture par e-mail »** envoie immédiatement le PDF en pièce jointe à l'adresse de facturation du client — idéal avec la facturation manuelle (envoyer une fois la facture créée et validée dans Dolibarr).

Chaque envoi est journalisé (Journal + ligne `DIWC :`), et le résultat (succès/échec) s'affiche sur la fiche commande.

### Créer une commande client brouillon dans Dolibarr (depuis la v1.10.0)

Sur la fiche commande (admin WooCommerce), le bouton **« Créer la commande brouillon Dolibarr »** crée une **commande client** Dolibarr au statut **Brouillon** (modifiable avant validation — aucune facture n'est créée) :

1. le **tiers** est retrouvé par l'**e-mail de facturation** de la commande ;
2. chaque **article** est relié à son produit Dolibarr via un **extrafield produit** (réglage *WooCommerce → Factures Dolibarr → Commande client Dolibarr (brouillon)*) : l'extrafield doit contenir l'ID du produit WooCommerce (défaut : `id_du_produit_dans_woocommerce`). L'ID de **déclinaison** est essayé en premier, puis l'ID du **produit parent** ;
3. les **lignes** reprennent quantité, prix unitaire **HT** et **TVA** de la commande WooCommerce ;
4. **si un article n'a pas de produit Dolibarr correspondant, la création est REFUSÉE** (réglage *Article sans produit Dolibarr*, depuis la v1.23.0) : aucun document n'est créé et **aucune ligne libre n'est fabriquée**. Le message indique alors, pour chaque article concerné, son **nom avec la déclinaison achetée** (attributs de la ligne) et l'**ID Woo recherché** (déclinaison / produit parent), afin de corriger l'extrafield côté Dolibarr. Le réglage peut être basculé sur « créer quand même » pour retrouver l'ancien comportement (ligne libre) ;
5. les **frais de port** sont ajoutés en **ligne libre**.

**Aperçu avant création (depuis la v1.16.0)** : la fiche commande affiche un tableau **« Aperçu des articles (WooCommerce → produits Dolibarr) »** — article, IDs Woo de la ligne (déclinaison / parent), **attributs de la ligne**, produit Dolibarr retenu, remarque. Trois cas sont **surlignés** :
- `⚠ incohérence WooCommerce : la ligne enregistre la déclinaison A, mais ses attributs (…) correspondent à la déclinaison B` → **le test décisif** : la ligne de commande conserve les **attributs choisis par le client** (copiés à l'achat) ; le plugin retrouve la déclinaison du catalogue qui correspond réellement à ces attributs. Si son ID diffère de celui enregistré, **l'ID de la ligne est erroné** (le choix du client, lui, est bon) — l'ID attendu est indiqué (`→ ID attendu : 1920`). Cause typique : la déclinaison a été supprimée/recréée (l'ID change), le produit a été converti simple ↔ variable, ou un import/une duplication a réattribué les ID. **Correction : côté WooCommerce** ;
- `⚠ incohérence WooCommerce : la déclinaison A appartient au produit « … » (ID …), pas au produit de la ligne` → la déclinaison enregistrée appartient à un **autre produit** ;
- `⚠ repli sur le PARENT (déclinaison non reliée)` ou `aucune correspondance : ligne libre` → extrafield manquant/modifié **côté Dolibarr**.

**Adresse de livraison à la création de la commande (depuis la v1.21.0)** : si l'adresse de livraison de la commande WooCommerce **diffère** de l'adresse de facturation, le plugin associe à la commande Dolibarr le **contact portant cette adresse** (`fk_delivery_address`) — celui créé automatiquement avec l'adresse de livraison, ou, à défaut, tout contact du tiers dont l'adresse correspond exactement. Si aucun contact ne correspond, l'adresse de livraison est **écrite directement sur la commande** (`address`, `zip`, `town`, `country_id`) pour ne jamais perdre l'information. Le choix effectué est **journalisé**. Si les adresses sont identiques, rien n'est transmis (Dolibarr utilise l'adresse du tiers).

**Correspondance des articles** (réglage *Correspondance des articles*) :

| Mode | Comportement |
|---|---|
| **Déclinaison puis parent** (défaut) | essaie l'ID de la déclinaison, puis celui du produit parent |
| **Déclinaison uniquement** | aucun repli : si l'extrafield de la déclinaison est absent, l'article n'est pas relié → création refusée par défaut (ou ligne libre si le réglage l'autorise) |
| **Parent uniquement** | utilise l'ID du produit parent |

Chaque ligne créée est **journalisée** (`Article « … » → produit Dolibarr REF (id N) via l'ID Woo X`), y compris les replis sur le parent, et la synchronisation du catalogue signale les **doublons d'extrafield** (même valeur sur deux produits Dolibarr — autre cause de correspondance erronée). Une création refusée faute de produit est journalisée elle aussi (`CRÉATION REFUSÉE — aucun produit Dolibarr pour « … »`).

La référence de la commande Dolibarr créée est **mémorisée** (meta de la commande WooCommerce) : le bouton devient **« Ouvrir dans Dolibarr »** (lien vers la commande brouillon), avec **« Recréer un brouillon »** (nouvelle commande, l'ancienne reste telle quelle) et **« Ne plus associer »** (déliaison locale, la commande reste dans Dolibarr). Chaque création est **journalisée**.

L'encart relit **l'état réel dans Dolibarr à chaque affichage** de la fiche commande : il affiche la **référence définitive** (attribuée à la validation) et le **statut courant** (Brouillon, Validée, En cours, Livrée, avec la date de validation). Rien n'est écrit automatiquement : si la référence Dolibarr diffère de celle mémorisée sur la commande, un bouton **« Mémoriser la référence Dolibarr »** apparaît — la mise à jour n'a lieu qu'après votre clic (et elle est journalisée).

> ⚠️ Prérequis : module Dolibarr **« Commandes »** actif et clé API avec le droit de **créer des commandes** (pas seulement lire les factures). Le filtrage des produits par extrafield (`t.custom_…`) est supporté par les versions récentes de Dolibarr (≥ 14/16). Sinon, le plugin **bascule sur un mapping du catalogue** : parcourez **WooCommerce → Factures Dolibarr → Catalogue produits Dolibarr**, cliquez **« Synchroniser le catalogue (arrière-plan) »** (WP-Cron requis — quelques minutes sur un gros catalogue), puis rechargez la page pour vérifier l'état du mapping avant de créer les commandes. Un article non mappé fait **échouer la création** (voir le réglage ci-dessus) ; le produit et l'ID manquants sont indiqués dans le message.

Le mapping est un **cache** : si l'ID WooCommerce change dans Dolibarr (ou si un produit est ajouté), il doit être rafraîchi. C'est fait automatiquement :
- **une fois par jour** (WP-Cron — la date de la prochaine exécution est affichée sur la page de réglages) ;
- **immédiatement à la demande** si un article n'est pas retrouvé lors d'une création de commande (relance au maximum une fois toutes les 10 minutes) ;
- ou **manuellement** avec le bouton « Synchroniser le catalogue ».

> 🛡️ **Depuis la v1.23.0, une synchronisation en échec ne détruit plus le mapping.** Si l'API Dolibarr renvoie une erreur (502/503 Cloudflare, coupure réseau, catalogue momentanément indisponible, réponse vide), la synchronisation **conserve le mapping précédent** et l'erreur est affichée sur la page de réglages (« Dernière synchronisation en échec — le mapping précédent a été conservé »). Auparavant une panne de quelques secondes suffisait à vider le cache : les créations suivantes partaient alors en lignes libres, donc avec un article générique dans Dolibarr.

Après une resynchronisation, les brouillons **déjà créés** ne sont pas modifiés : utilisez **« Recréer un brouillon »** sur la commande concernée pour obtenir des lignes reliées aux produits.

### Comparer et mettre à jour les coordonnées du client (depuis la v1.12.0)

Sur la fiche commande (admin), l'encart **« Coordonnées de facturation (WooCommerce ↔ Dolibarr) »** compare les informations du client avec celles du tiers Dolibarr et signale les **différences** :

| Champ comparé | Source WooCommerce | Champ Dolibarr |
|---|---|---|
| Raison sociale / nom | société, sinon « NOM Prénom » | `name` |
| Prénom / Nom (clients **particuliers** uniquement) | prénom, nom de facturation | `firstname`, `lastname` |
| E-mail | e-mail de facturation | `email` |
| Téléphone | téléphone de facturation | `phone` |
| Adresse | adresse 1 (+ adresse 2 à la ligne) | `address` |
| Code postal / Ville | code postal, ville | `zip`, `town` |
| Pays | code ISO du pays | `country_id` (via le dictionnaire des pays) |

**Règle selon le type de client (depuis la v1.15.0)** :
- **client société** (`billing_company` renseigné) → sur la fiche tiers : **raison sociale, e-mail, téléphone, adresse, CP, ville, pays**. Le **prénom/nom** de la personne n'est **pas** écrit sur le tiers (ce n'est pas son rôle dans Dolibarr) : un encart **« Contact (personne physique) »** propose de **créer** le contact Dolibarr rattaché au tiers (`POST /contacts` avec `socid`), ou de **mettre à jour** le contact existant champ par champ (e-mail identique, sinon nom + prénom) ;
- **client particulier** (pas de société) → prénom/nom restent synchronisables **sur le tiers** (comportement historique).
- La comparaison de la **raison sociale** tolère l'**ordre des mots** (« DUPONT Jean » = « Jean DUPONT »).

- les lignes **différentes** sont surlignées avec une case à cocher ; **aucun champ n'est pré-coché** : la mise à jour n'a lieu qu'après votre sélection et votre clic ;
- le bouton **« Mettre à jour dans Dolibarr les champs cochés »** n'envoie que les champs sélectionnés (`PUT /thirdparties/{id}`) ;
- ⚠️ cocher un champ **vide** côté WooCommerce **efface** la valeur correspondante dans Dolibarr ;
- chaque mise à jour est **journalisée** (champs modifiés) et les caches du plugin sont purgés.

> La comparaison fonctionne sur le tiers retrouvé par l'**e-mail de facturation** de la commande. Si **aucun compte Dolibarr ne correspond**, l'encart affiche les coordonnées qui seront utilisées et propose de **créer le tiers** dans Dolibarr (depuis la v1.14.0) : le tiers est créé comme **client** (code client généré par Dolibarr) avec raison sociale/nom, prénom, nom, e-mail, téléphone, adresse, code postal, ville et pays — toujours **après votre clic** sur « Créer ce tiers dans Dolibarr ». La création est journalisée (`Tiers Dolibarr créé : … (id X, code Y)`), puis les comparaisons et la création de commande brouillon utilisent ce nouveau compte.

### Adresse de livraison : application en une fois (depuis la v1.13.0)

L'encart **« Adresse de livraison (WooCommerce → Dolibarr) »** compare l'adresse de livraison de la commande WooCommerce avec celle portée par les documents Dolibarr :

- **Commande client Dolibarr** (créée par le plugin) ;
- **Facture Dolibarr liée** (retrouvée par « Réf. client » = numéro de commande).

> Si l'adresse de livraison est **identique à l'adresse de facturation**, l'encart **ne propose aucune mise à jour** : Dolibarr utilise par défaut l'adresse du compte client (celle du tiers). Un simple message le rappelle.

S'il y a une différence, un **seul bouton** applique l'adresse complète — **rue, code postal, ville, pays** — **d'un seul coup** sur la commande **et** sur la facture (`PUT /orders/{id}` et `PUT /invoices/{id}`). Aucune écriture sans ce clic. Le résultat est affiché par document (réussite / échec avec le message Dolibarr) et journalisé.

> Si la facture Dolibarr n'expose pas de champs d'adresse dans votre version, la mise à jour de la **commande** suffit : l'adresse de livraison affichée sur la facture en découle. L'encart indique alors l'échec éventuel pour la facture.

### Test

Connectez-vous avec un compte client ayant une commande facturée dans Dolibarr :

- **Mon compte → Commandes** : la colonne « Facture » affiche un bouton « Voir la facture » ;
- **Mon compte → Commande n°…** : un bloc « Facture » avec référence, date, montant et bouton ;
- Le PDF s'ouvre dans un nouvel onglet, l'URL affichée est celle de **votre site** (admin-post.php), jamais celle de Dolibarr.

---

## Sécurité

- Le bouton n'apparaît que pour la commande **appartenant au client connecté** (contrôle côté WooCommerce + nonce + vérification du propriétaire dans le handler de téléchargement). L'**admin** (capability `edit_shop_order`) est également autorisé pour le lien de vérification.
- En mode proxy, la **clé API Dolibarr n'est utilisée que côté serveur** et n'est jamais transmise au navigateur du client. L'URL de Dolibarr n'apparaît dans aucune page publique.
- Les erreurs de téléchargement affichent un **message générique** au client ; le détail est journalisé (`error_log`) pour l'admin.
- La clé API est stockée dans les options WordPress (non chiffrée — protégez le fichier `wp-config.php` et l'admin comme d'habitude).

---

## Dépannage

| Symptôme | Cause probable | Solution |
|---|---|---|
| « Erreur API Dolibarr HTTP 401/403 » (test de connexion) | Clé invalide ou droits insuffisants | Recréez la clé avec lecture des factures |
| « Erreur API Dolibarr HTTP 503 : Unknown column 't.custom_…' » (création commande brouillon) | Version de Dolibarr qui ne filtre pas les produits par extrafield dans la liste `/products` | Normal : le plugin utilise un **mapping du catalogue** construit en arrière-plan. Lancez **WooCommerce → Factures Dolibarr → Catalogue produits Dolibarr → Synchroniser** (WP-Cron), vérifiez la fin de la synchronisation dans le Journal, puis recréez la commande |
| « Erreur API Dolibarr HTTP 404 » | URL erronée ou module REST inactif | Vérifiez `https://dolibarr/api/index.php/invoices` dans un navigateur (avec la clé) |
| Colonne vide alors que la facture existe | `ref_client` ≠ numéro de commande, ou cache | Vérifiez `ref_client` dans Dolibarr ; passez en mode « ID manuel » ; réduisez le cache |
| Aucun PDF (mode proxy) | Facture non générée (PDF non construit) | Générez le PDF dans Dolibarr (bouton « Générer le PDF ») |
| « Facture temporairement indisponible » / **erreur 502** (Cloudflare ou hébergeur) | PDF non généré dans Dolibarr, timeout, certificat SSL, ou API injoignable | Voir « Erreur 502 » ci-dessous |
| Le client voit une page Dolibarr | Mode « auto » ou « public » actif | Repassez en mode **proxy** |

### Erreur 502 à l'ouverture d'une facture

Depuis la **v1.4.0**, un échec de téléchargement n'affiche plus de page 502 opaque :
- le **client** est redirigé vers sa commande avec le message « La facture est temporairement indisponible » ;
- l'encart admin de la commande affiche **« Dernière erreur de téléchargement »** avec le détail exact (à utiliser pour le diagnostic) ;
- le détail est aussi journalisé : voir le **Journal** sur la page WooCommerce → Factures Dolibarr (50 dernières entrées, bouton « Vider le journal ») — et dans le log PHP (lignes `DIWC :`).

Depuis la **v1.6.0**, le plugin **valide que la réponse est bien un PDF** (en-tête `%PDF-`) : si Dolibarr renvoie un code 200 avec une page d'erreur HTML/JSON, la tentative est rejetée et le plugin passe au chemin/modulepart suivant, au lieu d'afficher « Échec de chargement du document PDF ».

Depuis la **v1.6.1**, le plugin utilise en priorité l'**URL directe de téléchargement** exposée par Dolibarr sur la facture (`DIRECTDOWNLOAD_URL_INVOICE`) — le mécanisme officiel des versions récentes, qui renvoie le vrai PDF (l'endpoint `/documents/download` ne renvoie plus que le descripteur JSON dans ces versions).

Depuis la **v1.7.0 / v1.7.1**, le plugin récupère le PDF **embarqué en base64 dans les réponses JSON** de l'API : les versions récentes de Dolibarr (ex. 19+ / 23.x) renvoient le PDF dans le champ `content` (`"encoding": "base64"`) — via `/documents` et/ou `/documents/download` — au lieu du binaire pur (l'ancien endpoint `/documents/download` renvoie 404 sur ces versions). Le plugin décode ce contenu et le valide (`%PDF-`) : c'est la méthode fiable pour les Dolibarr récents.

Causes les plus fréquentes, dans l'ordre :

1. **Le PDF n'existe pas encore dans Dolibarr** : le téléchargement déclenche alors une génération (ODT → PDF) qui peut dépasser les limites de temps de l'hébergeur/Cloudflare. → Ouvrez la facture dans Dolibarr et cliquez **« Générer le PDF »**, puis réessayez. *(Depuis la v1.5.0, le plugin découvre le PDF par plusieurs moyens : endpoint dédié `/invoices/{id}/documents`, endpoint générique `/documents?modulepart=invoice` (puis `facture`), et nommage par défaut de Dolibarr `{réf}/{réf}.pdf` — ex. `FA2608-60152/FA2608-60152.pdf`. Le téléchargement essaie lui aussi les deux moduleparts.)*
2. **Certificat SSL de Dolibarr non reconnu** (auto-signé, chaîne incomplète) : le serveur WordPress refuse la connexion. → Ajoutez dans le `functions.php` de votre thème (ou un petit plugin) :
   ```php
   add_filter( 'diwc_sslverify', '__return_false' );
   ```
   ⚠️ À n'utiliser que si le certificat est vraiment fiable, et idéalement en HTTPS.
3. **Timeout hébergeur** : réduisez le temps de génération des PDF côté Dolibarr (PDF déjà générés = téléchargement rapide).
4. **Dolibarr derrière Cloudflare / protection bot** : le serveur WordPress reçoit une page de blocage au lieu du PDF. Vérifiez avec le bouton « Tester le téléchargement d'un PDF » (message « Erreur API Dolibarr HTTP 4xx » avec contenu HTML).
5. **Clé API sans droit de téléchargement** : donnez le droit **lecture** des factures (et des documents) à l'utilisateur API.

Les détails techniques sont journalisés dans le **Journal** du plugin (page de réglages, 50 dernières entrées) et, si `WP_DEBUG` est activé, également dans le log PHP du serveur (lignes commençant par `DIWC :`).

---

## Évolutions possibles

- Liste « Mes factures » complète dans Mon compte (toutes les factures du tiers, pas seulement par commande).
- Envoi automatique d'un e-mail avec le lien de facture à la validation de la facture (côté Dolibarr, natif).
- Automatisation de la création des factures via le connecteur officiel (Dolistore).

---

## Installation (depuis GitHub)

1. Téléchargez le zip de la dernière *Release* (ou clonez le dépôt et compressez le dossier `dolibarr-invoices-woocommerce`).
2. WordPress → **Extensions → Ajouter → Téléverser une extension** → sélectionnez le zip → **Installer** → **Activer**.
3. **WooCommerce → Factures Dolibarr** : renseignez l'URL de Dolibarr et la clé API, puis cliquez **« Tester la connexion »**.
4. Onglet **Catalogue produits Dolibarr** : cliquez **« Synchroniser le catalogue (arrière-plan) »**, puis rechargez la page et vérifiez que le nombre de produits mappés correspond à votre catalogue.

Les mises à jour ne se font pas automatiquement : réinstallez le zip d'une nouvelle release. Pour des mises à jour automatiques depuis ce dépôt, utilisez l'extension **Git Updater** — aucun code de mise à jour n'est embarqué dans le plugin.

---

## Données et vie privée

Le plugin n'envoie **rien** à son auteur : aucun appel sortant vers un serveur tiers, aucune télémétrie, aucun compte à créer.

Les données circulent uniquement entre **votre site** et **votre** instance Dolibarr, via son API REST :

- **envoyées à Dolibarr** lors d'une création de commande brouillon, de tiers ou de contact : nom, prénom, e-mail, adresse postale, téléphone, ainsi que les articles et montants de la commande ;
- **stockées localement** : l'URL et la clé API de Dolibarr dans l'option `diwc_settings`, le mapping produits dans une option dédiée, et les **50 dernières lignes du journal** (`diwc_log`) — ce journal peut contenir l'e-mail du client et des références de factures. Il est consultable et vidable depuis la page de réglages, et supprimé à la désinstallation.

---

## Historique des versions

- **1.23.1** — `error_log` limité à `WP_DEBUG`, journal non autoloadé, tâches planifiées nettoyées à la désinstallation, ajout de `LICENSE` et `.gitignore`, métadonnées de publication.
- **1.23.0** — une synchronisation en échec **ne détruit plus** le mapping du catalogue ; **refus** de créer la commande brouillon quand un article n'a pas de produit Dolibarr (nom, déclinaison et ID Woo manquant indiqués) ; nouveau réglage « Article sans produit Dolibarr ».
- **1.22.0** — contact de livraison associé à la commande brouillon, contacts pour les clients particuliers, téléphone via `phone_pro` / `phone_mobile`.
- **1.21.0** — adresse de livraison transmise à la création de la commande brouillon.
- **1.16.0** — aperçu de la correspondance des articles (déclinaison, attributs, ID attendu) avant création.
- **1.12.0** — comparaison et mise à jour interactive des coordonnées du client (tiers et contacts).
- **1.10.0** — création de la commande client brouillon dans Dolibarr depuis la fiche commande.
- **1.5.0** — découverte du PDF par plusieurs moyens (endpoint dédié, `/documents`, nommage par défaut).

---

## Licence

[GPL-2.0-or-later](LICENSE) — logiciel fourni tel quel, sans garantie. Adaptez les identifiants et la logique de correspondance à votre installation.

Ce plugin n'est ni édité, ni parrainé, ni soutenu par Dolibarr ou par Automattic/WooCommerce ; les noms cités le sont à titre d'information.
