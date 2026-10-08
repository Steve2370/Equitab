# Equitab

> Plateforme de partage de coûts d'abonnements numériques — paiements sécurisés par Stripe, identifiants chiffrés, zéro argent transit par Equitab.

![Laravel](https://img.shields.io/badge/Laravel-13-FF2D20?style=flat&logo=laravel)
![Vue](https://img.shields.io/badge/Vue-3-4FC08D?style=flat&logo=vue.js)
![TypeScript](https://img.shields.io/badge/TypeScript-5-3178C6?style=flat&logo=typescript)
![Stripe](https://img.shields.io/badge/Stripe-Connect-635BFF?style=flat&logo=stripe)
![PostgreSQL](https://img.shields.io/badge/PostgreSQL-16-4169E1?style=flat&logo=postgresql)
![Docker](https://img.shields.io/badge/Docker-Compose-2496ED?style=flat&logo=docker)

---

## Table des matières

- [Aperçu](#aperçu)
- [Architecture](#architecture)
- [Stack technique](#stack-technique)
- [Fonctionnalités](#fonctionnalités)
- [Prérequis](#prérequis)
- [Installation locale](#installation-locale)
- [Variables d'environnement](#variables-denvironnement)
- [Commandes de développement](#commandes-de-développement)
- [Structure du projet](#structure-du-projet)
- [API Routes](#api-routes)
- [Flux de paiement](#flux-de-paiement)
- [Devises CAD/EUR](#devises-cadeur)
- [Sécurité](#sécurité)
- [Déploiement](#déploiement)
- [Tests](#tests)

---

## Aperçu

Equitab permet à des particuliers canadiens de partager les frais de leurs abonnements numériques (Netflix, Spotify, Disney+, etc.) de façon sécurisée. Un propriétaire crée un groupe, des membres le rejoignent et paient leur quote-part directement via Stripe — Equitab ne détient jamais les fonds et prélève une commission de 5% via `application_fee_percent`.

**Modèle légal** : partage de frais entre particuliers, pas revente d'abonnements.

---

## Architecture

```
┌─────────────────────────────────────────────────────────┐
│                        Equitab                          │
│                                                         │
│  Vue 3 + Inertia.js          Laravel 13 + PHP 8.4       │
│  TypeScript + Tailwind v4    PostgreSQL + Redis         │
│                                                         │
│  Stripe Elements <─> Stripe Connect                     │
│  (saisie carte)              (transfer direct owner)    │
│                                                         │
│  Laravel Reverb <─> WebSocket (chat temps réel).        │
│  Cloudflare R2               Stockage avatars           │
└─────────────────────────────────────────────────────────┘
```

### SOLID & Patterns

- **Repository Pattern** : `GroupRepository`, `PaymentRepository`, `WalletRepository`
- **Strategy Pattern** : `PaymentGatewayResolver` (Stripe / PayPal)
- **Dependency Inversion** : tous les services injectés via interfaces
- **Single Responsibility** : controllers minces, logique dans les services

---

## Stack technique

| Couche | Technologie |
|---|---|
| Backend | Laravel 13, PHP 8.4, PostgreSQL 16, Redis 7 |
| Frontend | Vue 3, Inertia.js, TypeScript, Tailwind CSS v4 |
| Paiements | Stripe Connect, Stripe Subscriptions, Stripe Identity |
| Temps réel | Laravel Reverb (WebSockets) |
| Stockage | Cloudflare R2 (avatars) |
| Infrastructure | Docker, Nginx, PHP-FPM |
| Icons | Lucide Vue Next |
| Font | Montserrat (Google Fonts) |

---

## Fonctionnalités

### Propriétaire (partage un abonnement)
-  Inscription + vérification d'identité (Stripe Identity)
-  Onboarding bancaire (Stripe Connect Express)
-  Création de groupe avec identifiants chiffrés (AES-256)
-  Choix du tier (Standard / Premium / Famille)
-  Visibilité (Public / Sur invitation / Privé)
-  Réception automatique des paiements via Stripe Connect
-  Commission 5% prélevée automatiquement par Equitab

### Membre (rejoint un abonnement)
-  Recherche et filtrage par service
-  Paiement sécurisé par carte (Stripe Elements)
-  Pro-rata premier mois + ancrage au 1er du mois
-  Facturation mensuelle automatique (Stripe Subscriptions)
-  Accès aux identifiants chiffrés après paiement confirmé
-  Remboursement automatique si identifiants non fournis (48h)
-  Dispute manuelle avec révision admin

### Dashboard
-  Tableau de bord avec métriques (économies, dépenses, renouvellements)
-  Gestion des abonnements (rejoints + partagés)
-  Historique des paiements avec filtres
-  Chat temps réel (WebSockets via Reverb)
-  Profil avec vérification d'identité et compte bancaire
-  Préférences (avatar R2, notifications, langue, confidentialité)
-  Danger zone (suppression de compte)

### Sécurité
-  Rate limiting par route (30-120 req/min)
-  CSRF protection
-  Headers HTTP sécurisés (X-Frame-Options, CSP, HSTS)
-  Identifiants chiffrés avec `encrypted` cast Laravel
-  Webhook Stripe avec vérification de signature
-  Idempotence des webhooks (table `stripe_events`)
-  Policies Laravel (GroupPolicy, etc.)
-  Form Request validation

---

## Prérequis

- Docker Desktop
- Node.js 20+
- Stripe CLI (`brew install stripe/stripe-cli/stripe`)
- Compte Stripe (mode test)
- Compte Cloudflare R2 (gratuit jusqu'à 10 GB)

---

## Installation locale

### 1. Cloner le projet

```bash
git clone https://github.com/Steve2370/equitab.git
cd equitab
```

### 2. Variables d'environnement

```bash
cp .env.example .env
```

Remplis les variables (voir section [Variables d'environnement](#variables-denvironnement)).

### 3. Lancer Docker

```bash
docker compose up -d
```

### 4. Installer les dépendances PHP

```bash
docker compose exec app composer install
docker compose exec app php artisan key:generate
```

### 5. Migrations et données de base

```bash
docker compose exec app php artisan migrate
docker compose exec app php artisan db:seed
```

Ou manuellement :

```bash
docker compose exec app php artisan tinker --execute="
\$cat = \App\Models\SubscriptionCategory::create(['name' => 'Streaming']);
\$services = [
    ['name' => 'Netflix', 'monthly_price' => 1999, 'max_members' => 4],
    ['name' => 'Spotify', 'monthly_price' => 1599, 'max_members' => 6],
    ['name' => 'Disney+', 'monthly_price' => 1399, 'max_members' => 4],
    [Vous pouvez ajouter un service que vous souhaitez partager],
];
foreach (\$services as \$s) {
    \App\Models\Subscription::create([
        'category_id' => \$cat->id, 'name' => \$s['name'],
        'slug' => str(\$s['name'])->slug(), 'max_members' => \$s['max_members'],
        'monthly_price' => \$s['monthly_price'], 'currency' => 'CAD',
        'billing_cycle' => 'monthly', 'is_active' => true, 'tier' => 'standard',
    ]);
}
echo 'OK';
"
```

### 6. Installer les dépendances Node

```bash
npm install
```

---

## Variables d'environnement

Copie `.env.example` en `.env` et remplis :

```env
APP_NAME=Equitab
APP_ENV=local
APP_KEY=                          # généré par php artisan key:generate
APP_URL=http://localhost:8000

DB_CONNECTION=pgsql
DB_HOST=equitab_postgres
DB_PORT=5432
DB_DATABASE=equitab
DB_USERNAME=equitab
DB_PASSWORD=secret

REDIS_HOST=equitab_redis
REDIS_PORT=6379

# Stripe
STRIPE_KEY=pk_test_...
STRIPE_SECRET=sk_test_...
STRIPE_WEBHOOK_SECRET=whsec_...   # fourni par stripe listen
VITE_STRIPE_KEY="${STRIPE_KEY}"

# Stripe Connect
STRIPE_CONNECT_CLIENT_ID=ca_...

# Cloudflare R2
CLOUDFLARE_R2_ACCESS_KEY_ID=
CLOUDFLARE_R2_SECRET_ACCESS_KEY=
CLOUDFLARE_R2_BUCKET=equitab-avatars
CLOUDFLARE_R2_ENDPOINT=https://ACCOUNT_ID.r2.cloudflarestorage.com
CLOUDFLARE_R2_URL=https://pub-xxxxx.r2.dev

# Laravel Reverb (WebSockets)
REVERB_APP_ID= (Pour le chat en temps reel)
REVERB_APP_KEY=
REVERB_APP_SECRET=
REVERB_HOST=localhost
REVERB_PORT=8080
REVERB_SCHEME=http
VITE_REVERB_APP_KEY="${REVERB_APP_KEY}"
VITE_REVERB_HOST="${REVERB_HOST}"
VITE_REVERB_PORT="${REVERB_PORT}"
VITE_REVERB_SCHEME="${REVERB_SCHEME}"

QUEUE_CONNECTION=database
```

---

## Commandes de développement

Les 4 terminaux à garder ouverts simultanément :

```bash
# Terminal 1 — Frontend (Vite HMR)
npm run dev

# Terminal 2 — WebSockets (Reverb)
docker compose exec app php artisan reverb:start --host=0.0.0.0 --port=8080

# Terminal 3 — Queue worker (jobs, remboursements automatiques)
docker compose exec app php artisan queue:work --verbose

# Terminal 4 — Webhooks Stripe (forwarding local)
stripe listen --forward-to localhost:8000/webhooks/stripe
```

### Commandes utiles

```bash
# Vider les caches
docker compose exec app php artisan config:clear
docker compose exec app php artisan route:clear

# Migrations fraîches
docker compose exec app php artisan migrate:fresh

# Logs en temps réel
docker compose exec app tail -f storage/logs/laravel.log

# Tinker (REPL)
docker compose exec app php artisan tinker
```

---

## Structure du projet

```
equitab/
├── app/
│   ├── Http/
│   │   ├── Controllers/
│   │   │   ├── Api/
│   │   │   │   ├── GroupController.php
│   │   │   │   ├── PaymentController.php
│   │   │   │   ├── StripeWebhookController.php
│   │   │   │   └── WalletController.php
│   │   │   ├── ChatController.php
│   │   │   └── DashboardController.php
│   │   └── Middleware/
│   ├── Jobs/
│   │   └── CheckCredentialsProvided.php   # Remboursement auto 48h si le user n'a toujours pas reçu les ID de connexion
│   ├── Models/
│   │   ├── Group.php
│   │   ├── GroupMember.php
│   │   ├── Payment.php
│   │   ├── Subscription.php
│   │   └── User.php
│   ├── Repositories/
│   │   ├── Contracts/
│   │   └── GroupRepository.php
│   └── Services/
│       ├── Group/GroupService.php
│       └── Payment/
│           ├── Contracts/PaymentGatewayInterface.php
│           ├── PaymentGatewayResolver.php
│           ├── PaymentService.php
│           └── StripeGateway.php
├── resources/
│   └── js/
│       ├── Components/
│       │   ├── Dashboard/MetricCard.vue
│       │   ├── CredentialsModal.vue
│       │   ├── OwnerGroupCard.vue
│       │   ├── ScrollingCarousel.vue
│       │   ├── ServiceCard.vue
│       │   └── StripeCardForm.vue
│       ├── Layouts/DashboardLayout.vue
│       └── Pages/
│           ├── Auth/
│           ├── Dashboard/
│           │   ├── Index.vue
│           │   ├── Subscriptions.vue
│           │   ├── Payments.vue
│           │   ├── Chat.vue
│           │   ├── Profile.vue
│           │   └── Preferences.vue
│           ├── ServiceGroups.vue
│           ├── PaymentSuccess.vue
│           ├── Error.vue
│           └── Welcome.vue
├── docker/
│   └── nginx/default.conf
├── docker-compose.yml
└── Dockerfile
```

---

## API Routes

### Publiques
| Méthode | Route | Description |
|---|---|---|
| `POST` | `/api/register` | Inscription |
| `POST` | `/api/login` | Connexion |
| `GET` | `/api/groups` | Liste des groupes |
| `GET` | `/api/groups/{group}` | Détail d'un groupe |
| `GET` | `/api/groups/{group}/proration` | Calcul pro-rata |

### Authentifiées (Sanctum)
| Méthode | Route | Description |
|---|---|---|
| `POST` | `/api/groups` | Créer un groupe |
| `POST` | `/api/groups/{group}/subscribe` | S'abonner |
| `GET` | `/api/groups/{group}/credentials` | Voir les identifiants |
| `POST` | `/api/subscriptions/confirm` | Confirmer l'abonnement |
| `POST` | `/api/payments/{payment}/dispute` | Ouvrir une dispute |
| `POST` | `/api/stripe/onboarding` | Onboarding Connect |
| `POST` | `/api/stripe/identity` | Vérification identité |
| `GET` | `/api/groups/{group}/messages` | Messages du chat |
| `POST` | `/api/groups/{group}/messages` | Envoyer un message |

### Webhook
| Méthode | Route | Description |
|---|---|---|
| `POST` | `/webhooks/stripe` | Webhook Stripe (CSRF exempt) |

---

## Flux de paiement

```
Membre clique "S'abonner"
    ↓
Stripe Elements — saisie carte (jamais transmise à Equitab)
    ↓
createPaymentMethod() → paymentMethodId
    ↓
POST /api/groups/{group}/subscribe
    ↓
StripeGateway::createSubscription()
    ├── Crée Customer Stripe si inexistant
    ├── Crée Subscription avec billing_cycle_anchor (1er du mois)
    ├── application_fee_percent: 5% → Equitab
    └── transfer_data.destination → compte propriétaire
    ↓
Subscription active → confirmOnBackend()
    ↓
GroupMember status: active
Payment créé en DB
CheckCredentialsProvided dispatché (48h)
    ↓
Si identifiants non fournis après 48h → remboursement automatique
```

---

## Devises CAD/EUR

Les montants contractuels restent des entiers en cents. Chaque groupe conserve
sa propre `currency`, figée dès sa publication ; le catalogue conserve sa devise
indépendante. Modifier le catalogue ne convertit ni les groupes ni les paiements.
Changer la devise d'un brouillon impose de ressaisir le prix. Les rapports,
historiques et courriels distinguent CAD et EUR ; aucun total multidevise ni taux
de change implicite n'est calculé.

`EQUITAB_EUR_ENABLED=false` est le réglage par défaut. Il autorise la préparation
des brouillons EUR mais bloque leur publication et les nouvelles réservations ou
tentatives de paiement EUR. Une tentative financière déjà persistée garde ses
paramètres et sa clé d'idempotence. Les renouvellements, recalculs et remboursements
des engagements EUR existants restent traitables lorsque ce réglage est désactivé.
Le recalcul des parts concerne les groupes ouverts **et complets** : l'arrivée
du dernier membre ne doit pas figer le prix des membres précédents. Les groupes
fermés restent exclus. Le prix recalculé conserve la devise du groupe et la
politique de prorata existante.

Avant toute activation EUR : valider les pays propriétaires autorisés et
l'éligibilité des comptes Connect, puis effectuer le parcours propriétaire/membre
dans une sandbox Stripe isolée (authentification, webhook, renouvellement,
recalcul et remboursement). Les doubles automatisés ne remplacent pas cette
recette. Ce réglage n'ouvre aucun nouveau pays à lui seul.

Pour les destination charges sans `on_behalf_of`, la devise encaissée, celle du
solde de la plateforme et celle du règlement au propriétaire peuvent différer.
Comparer directement le montant EUR de la charge au montant CAD du transfert
est incorrect. Le vérificateur de recette
`scripts/stripe-qa/DestinationChargeProof.php` contrôle les liens entre objets,
la destination, les montants dans chaque devise, les taux fournis par Stripe et
la commission ; il refuse les objets live et n'effectue aucun appel ni écriture.
Son périmètre est CAD vers propriétaire réglé en CAD et EUR vers propriétaire
réglé en EUR ; un autre règlement nécessite une recette distincte. Les 5 % sont
une commission brute, pas une garantie de bénéfice net après frais et change.

La migration `2026_10_07_180000_add_native_currency_to_groups` déduit la devise
historique des paiements, des prix et des tentatives chiffrées. Sans engagement
financier, elle utilise le catalogue. Une ambiguïté arrête la migration entière
sans conversion. Prévoir une sauvegarde vérifiée et interrompre les écritures
web/queue/scheduler pendant la migration et le remplacement du code ; conserver
la clé de chiffrement existante. Tester d'abord sur une copie de recette protégée.
Ne pas ignorer un échec ou réécrire l'historique pour le contourner.

Le retour arrière du schéma est refusé dès qu'il ferait perdre un contrat EUR
(y compris archivé) ou réinterpréterait la devise d'un groupe. Pour suspendre les
nouvelles opérations EUR, désactiver le réglage puis recharger la configuration
et les workers ; conserver le code capable de traiter les engagements existants.

### Catalogue sur invitation : Dropbox Family et NordPass Family

La migration `2026_10_08_000100_prepare_invitation_catalog` autorise un prix
catalogue et un cycle fournisseur inconnus (`null`) et ajoute `access_mode`
(`credentials` par défaut, sans modifier les groupes historiques).
Elle ne crée ni n'active d'offre. La migration suivante
`2026_10_08_000300_activate_selected_invitation_services` applique le choix de
l'exploitant : création/activation de **Dropbox Family et NordPass Family** et
retrait de **Bitwarden Families**. Un déploiement exécutant `php artisan migrate
--force` applique donc aussi les données du catalogue, sans seeder global.
Contrôle ou réapplication ciblée :

```bash
php artisan equitab:prepare-invitation-services --dry-run
php artisan equitab:prepare-invitation-services --apply
```

Sans option, la commande simule seulement. `InvitationServiceSeeder` utilise le
même mécanisme ; ne pas lancer tous les seeders pour ces ajouts. Le lot est
transactionnel : collision de nom/slug (y compris casse/espaces), devise/mode
incompatibles ou catégorie ambiguë = refus sans changement. Une relance préserve
identifiants, prix, vérification et groupes existants ; elle réactive uniquement
les deux offres choisies. Elle ne constitue pas une vérification fournisseur.
Bitwarden est supprimé seulement sans groupe ni brouillon associé (archives
incluses). Sinon il est désactivé, sans supprimer contrats, membres ou paiements.
Ses anciennes icônes et son mode d'accès restent compatibles avec cet historique.
Les nouvelles publications/adhésions sont refusées ; les engagements Stripe
déjà persistés restent récupérables et les accès des membres existants conservés.

| Offre préparée | Catégorie / icône locale | Données nouvelles |
| --- | --- | --- |
| Dropbox Family (`dropbox-family`) | Productivité / `dropbox.svg` | CAD, 6 personnes propriétaire inclus, prix et cycle inconnus |
| NordPass Family (`nordpass-family`) | Sécurité / `nordpass.png` | CAD, 6 personnes propriétaire inclus, prix et cycle inconnus |

Les deux nouvelles offres sont `is_active=true`, `is_verified=false`,
`access_mode=invitation`, `tier=famille`. Elles sont proposées au public et dans
le sélecteur propriétaire ; aucun faux groupe ni sixième place invitée n'est
créé. Les icônes fournies restent dans `public/Images/services/`.
La devise CAD désigne la future cotisation EquitAb, **pas un tarif canadien
officiel du fournisseur** ; aucune variante EUR n'est créée.

Décision produit : les cotisations EquitAb restent mensuelles et le propriétaire
saisit son coût réel total ramené au mois avant répartition. Aucun prix nul ou
promotionnel fictif n'est prérempli. Un paiement annuel fournisseur reste annuel :
le propriétaire avance ce coût, sans transformer son contrat en abonnement mensuel.
La préparation ne change ni les commissions ni les règles de résiliation.

Preuves officielles consultées le **7 octobre 2026** :

- [Dropbox Family](https://help.dropbox.com/plans/dropbox-family-plan) : six
  comptes individuels au total, dont le responsable (cinq invitations), quota
  partagé de 2 To, usage personnel. Les invitations et retraits se gèrent chez
  Dropbox. Cette description ne prouve pas une autorisation de revente payante.
- [Bitwarden Families](https://bitwarden.com/help/password-manager-plans/) :
  propriétaire et cinq proches, facturation annuelle. Les
  [conditions, section C.3](https://bitwarden.com/terms/) exigent une permission
  écrite expresse pour revendre l'accès ; aucune permission n'est présumée ici.
  [Ajout des membres](https://bitwarden.com/help/managing-users/) : invitation
  par courriel, acceptation chez Bitwarden puis confirmation du propriétaire.
  Le lien d'invitation partageable est réservé à Enterprise, pas à Families ;
  afficher un paiement réussi n'automatise pas ces étapes fournisseur.
- [NordPass Family](https://support.nordpass.com/hc/en-us/articles/360006700458-Premium-vs-Free-version-of-NordPass) :
  six comptes pour la famille et les amis. Cette page ne valide ni un tarif CAD
  récurrent ni une commercialisation à des inconnus ; le cycle reste inconnu.

L'activation demandée par l'exploitant n'est pas une autorisation des fournisseurs :
les droits de partage payant, marchés et modalités restent à clarifier. Le parcours
réel de livraison/révocation chez chaque fournisseur reste à vérifier séparément
des tests automatisés de l'application. Ne jamais collecter
de mot de passe maître, coffre ou code de récupération. Ne pas fabriquer des
identifiants pour contourner la protection d'un membre sans accès.

Déployer ce lot en maintenance, workers arrêtés, requêtes en cours terminées,
avec sauvegarde préalable. La commande `--apply` exige la même fenêtre de
maintenance : ne pas modifier ce catalogue pendant des sauvegardes de brouillons.
Retour arrière : la migration de choix du catalogue refuse de deviner les anciens
statuts ou de recréer des lignes supprimées. Utiliser une correction ciblée ou la
sauvegarde vérifiée ; conserver le schéma/code compatible si une offre utilise le
mode invitation ou une valeur inconnue. Le `down()` refuse ces cas plutôt que
d'inventer un montant, de perdre le mode d'accès ou de supprimer une offre liée.

## Accès après paiement : identifiants et invitations

Le serveur reste la source de vérité : facture Stripe payée, paiement vérifié,
adhésion active et non expirée. Le retour du navigateur, l’URL de succès ou une
case cochée ne donnent aucun accès. `GET /api/groups/{group}/service-access`
renvoie `payment_pending`, `awaiting_owner`, `ready` (éléments mis à disposition,
pas preuve de bon fonctionnement) ou `unavailable`. Les secrets sont chiffrés,
absents des props/historiques Inertia et fournis seulement par une réponse
authentifiée `private, no-store`. La page et la fenêtre d’accès se mettent à jour
automatiquement, avec attente bornée et bouton de reprise sans nouveau paiement.

Dans **Mes abonnements → Je partage → Gérer les accès**, le propriétaire peut
mettre à jour une paire complète d’identifiants ou fournir une invitation par
membre. Le mode est fixé à la création du groupe ; il ne dépend pas d’un choix
envoyé par le client. Les liens HTTPS sont limités aux domaines prévus des
fournisseurs, sans requête distante : leur validité fonctionnelle n’est donc pas
certifiée. Pour un éventuel **groupe historique** Bitwarden Families, le courriel fournisseur reste pris en charge (envoi déclaré
par le propriétaire après paiement, acceptation puis confirmation dans Bitwarden),
pas un lien Enterprise inventé ni un mot de passe maître. Le membre n’a aucune
confirmation à faire **dans EquitAb** pour conserver son accès ou son paiement.

Le contrôle prévu après 48 h est conservé pour les éléments non fournis ; ni
l’absence d’ouverture ni le silence du membre ne déclenchent un remboursement.
Les nouveaux paiements utilisent `access_check_version=2` : identifiant **et**
mot de passe, ou invitation destinataire enregistrée/envoi fournisseur déclaré.
Les paiements historiques conservent la version 1 pour éviter des remboursements
rétroactifs liés au changement de règle. Une intention de remboursement déjà
engagée reste durable et prioritaire. Fourniture et contrôle automatique utilisent
le même verrou ; aucun secret factice ne remplace une invitation. Un signalement
d’accès invalide ouvre un dossier via le flux de litige existant, sans remboursement
automatique sur simple déclaration du membre. Une mise à disposition enregistrée
n’est pas une preuve indépendante de connexion chez le fournisseur.
La trace de livraison est conservée après un retrait : recevoir l’invitation puis
quitter le groupe ne transforme pas cet accès livré en accès « non fourni » au
contrôle des 48 h. Le lien retiré ne reste pas consultable.

Après annulation/expiration, l’accès EquitAb est refusé. Les invitations à retirer
sont signalées au propriétaire, même pour un groupe archivé. Il doit réellement
retirer le membre chez le fournisseur puis le déclarer ; EquitAb ne prétend pas
effectuer une révocation distante. Un lien déjà copié ne peut pas être effacé du
côté du membre par la seule révocation EquitAb.

Déploiement distinct : sauvegarder, arrêter workers/scheduler, appliquer les deux
migrations additives, remplacer l’application et reconstruire les assets, puis
redémarrer les processus compatibles. Garder `APP_KEY` et les flags Europe actuels.
La commande de préparation du catalogue n’active aucun service. Ne pas utiliser
`migrate:rollback` après de nouveaux accès/paiements : le retour arrière refuse
la perte de ces contrats ; conserver le schéma et les workers compatibles.

## Propriétaires : Canada et zone euro

Le pays du propriétaire est demandé explicitement dans son profil ou lors de
l'activation des versements. Il est indépendant de la devise du groupe. Les pays
pris en charge sont le Canada et les 21 États de la zone euro : Allemagne,
Autriche, Belgique, Bulgarie, Chypre, Croatie, Espagne, Estonie, Finlande,
France, Grèce, Irlande, Italie, Lettonie, Lituanie, Luxembourg, Malte, Pays-Bas,
Portugal, Slovaquie et Slovénie. Le Royaume-Uni, la Suisse et les pays de l'UE
hors zone euro ne sont pas inclus dans cette ouverture.

`EQUITAB_EUROZONE_CONNECT_ENABLED=false` bloque la création de nouveaux comptes
Connect européens, mais laisse préparer les profils et brouillons. Le Canada
reste disponible. Ce réglage est **distinct** de `EQUITAB_EUR_ENABLED` : il faut
valider puis activer les deux pour ouvrir le parcours propriétaire européen en
EUR. Ni la migration ni le déploiement du code ne les activent automatiquement.

Le pays est figé dès qu'une tentative Connect a été persistée, avant l'appel
réseau. Les reprises réutilisent le même compte ou les mêmes paramètres chiffrés
et la même clé, même si le réglage est ensuite désactivé. Une tentative ambiguë
de plus de 23 heures nécessite une réconciliation opérateur ; ne jamais supprimer
sa trace pour la relancer. Les comptes existants ne sont ni recréés ni affectés à
un pays deviné ; leur pays historique peut rester non renseigné localement.
Un changement de pays avant activation efface l'ancienne adresse du profil pour
éviter de transmettre une adresse canadienne avec un compte belge, par exemple.

La migration `2026_10_07_190000_add_owner_country_to_users` est additive et
élargit les champs région/code postal. Son retour arrière refuse de perdre un
pays renseigné ou de tronquer une adresse. Sauvegarder et interrompre les écritures
pendant les migrations. Pour fermer de nouvelles inscriptions européennes,
désactiver le réglage et recharger la configuration ainsi que les workers, sans
supprimer les comptes ni casser les engagements existants.

Avant ouverture publique :

- Vérifier l'éligibilité **du compte Stripe EquitAb** aux versements Canada/EEE,
  les pays activés dans Connect, les capacités et les devises de règlement.
  La disponibilité générale dans la documentation ne garantit pas celle du compte.
- Réaliser une recette Stripe sandbox : propriétaire canadien et européen
  (notamment Belgique), lien expiré/reprise, identité, activation, membre avec
  authentification 3D Secure, webhook rejoué, renouvellement et remboursement.
  Vérifier le transfert et les frais réels ; aucune conversion ni baisse de frais
  n'est promise par l'application.
- Valider les conditions de partage et la disponibilité territoriale de chaque
  service, les obligations de protection des données, consommateurs, fiscalité
  et documents contractuels applicables. L'interface reste francophone ; le code
  n'est pas une certification juridique ou une autorisation du fournisseur.

Sources officielles consultées le 7 octobre 2026 :
[zone euro](https://european-union.europa.eu/institutions-law-budget/euro/countries-using-euro_en),
[Stripe Connect transfrontalier](https://docs.stripe.com/connect/cross-border-payouts),
[onboarding Express international](https://docs.stripe.com/connect/express-accounts).
Le flux existant de destination charges sans `on_behalf_of`, le taux de commission
et les vérifications d'identité sont conservés.

## Sécurité

### Webhooks Stripe
Tous les webhooks vérifient la signature via `Webhook::constructEvent()`. Les événements déjà traités sont ignorés (idempotence via `stripe_events`).

**Événements écoutés :**
- `invoice.paid`
- `invoice_payment.paid`
- `invoice.payment_failed`
- `customer.subscription.deleted`
- `account.updated`
- `identity.verification_session.verified`
- `identity.verification_session.processing`

### Données sensibles
- Identifiants de service chiffrés avec `'encrypted'` cast Laravel (AES-256-CBC + APP_KEY)
- Avatars stockés sur Cloudflare R2 (jamais en DB)
- Mots de passe hashés bcrypt
- Variables d'environnement hors du repo Git

### Headers HTTP
```nginx
X-Frame-Options: SAMEORIGIN
X-Content-Type-Options: nosniff
Referrer-Policy: strict-origin-when-cross-origin
X-XSS-Protection: 1; mode=block
Strict-Transport-Security: max-age=31536000 (production)
```

---

## Déploiement

### Prérequis serveur
- Ubuntu 24.04
- Docker + Docker Compose
- Nginx (reverse proxy)
- Certbot (SSL Let's Encrypt)

### Variables production

```env
APP_ENV=production
APP_DEBUG=false
APP_URL=https://equitab.com

# Clés Stripe live (pas test)
STRIPE_KEY=pk_live_...
STRIPE_SECRET=sk_live_...
STRIPE_WEBHOOK_SECRET=whsec_...  # endpoint production

# HTTPS obligatoire pour Stripe.js
REVERB_SCHEME=https
```

### Webhooks Stripe production
Dans le dashboard Stripe → Webhooks → Ajouter endpoint :
- URL : `https://equitab.com/webhooks/stripe`
- Événements : voir liste ci-dessus

### Supervisor (queue + reverb en daemon)

```ini
[program:equitab-queue]
command=php /var/www/artisan queue:work --sleep=3 --tries=3
autostart=true
autorestart=true

[program:equitab-reverb]
command=php /var/www/artisan reverb:start --host=0.0.0.0 --port=8080
autostart=true
autorestart=true
```

---

## Tests

### Aperçu isolé de la refonte

Lancer `npm run preview:design`, puis ouvrir [l’aperçu local](http://127.0.0.1:4173).
Ce serveur écoute uniquement sur la machine locale. Il n’amorce pas Laravel, ne
charge pas les fichiers d’environnement et n’utilise ni base de données, ni
Stripe, ni messagerie temps réel. Les prix et profils sont entièrement fictifs.
Les soumissions et requêtes de modification sont bloquées.

L’aperçu couvre l’accueil, le catalogue, les groupes, la connexion, l’inscription,
le tableau de bord, les abonnements, les paiements, les conversations, le profil,
les préférences et la création de groupe. Les données sont fictives et les écritures
sont interdites. Ce n’est pas une validation des parcours de paiement,
d’identité, de messagerie en temps réel ou d’authentification réels.

Cas de vérification disponibles :

- `/services?search=Spotify` : recherche initiale.
- `/services?empty=1` et `/?empty=1` : catalogue vide.
- `/services?signedin=1` : navigation d’un membre connecté.
- `/dashboard?empty=1` : nouveau membre.
- `/dashboard` : membre avec abonnements et échéance fictifs.

La commande `npm run build` compile l’application réelle. L’entrée de prévisualisation
est séparée de l’application publiée. L’aperçu se ferme avec Ctrl+C.

#### Direction issue du brief d’inspirations

La page `/direction` présente une proposition indépendante : trois scènes de
cartes originales, filtres, recherche, favoris temporaires et détails en fenêtre
modale. Elle ne remplace pas les pages de production. Les illustrations réagissent
au pointeur ; les actions restent accessibles au toucher et au clavier. Le réglage
« Animations » respecte aussi la préférence système de réduction des mouvements.

La source du skill dédié se trouve dans `skills/equitab-experience/`. Son fichier
de références distingue les exigences du brief des choix créatifs proposés.
Pour utiliser un autre port local : `EQUITAB_PREVIEW_PORT=4175 npm run preview:design`.
Ce prototype doit être vérifié via cet aperçu, car `npm run build` ne l’inclut pas.

#### Connexion et catalogue — direction collection

Les pages réelles `/login` et `/services` utilisent désormais cette direction.
La connexion conserve ses routes, ses champs, Google, la récupération du mot de
passe et les erreurs Inertia. Le découpage est de 70/30 à partir de 1100 px ; sous
ce seuil, le formulaire passe avant la présentation.

L’animation originale `public/media/equitab-story.mp4` dure 18 secondes, sans son.
Elle dispose d’une affiche, d’un contrôle lecture/pause, de trois étapes
sélectionnables et d’une explication textuelle. Elle ne démarre pas automatiquement
sur mobile ni avec la préférence système de réduction des mouvements, et se met
en pause hors écran ou lorsque la page est masquée. Le fichier préexistant
`public/Images/Equitab.mp4` n’a pas été modifié.
L’étiquette superposée « Le plaisir de partager / 001 » a été retirée. Le rendu
de l’animation charge les mêmes logos et réglages de cadrage que l’accueil depuis
`servicePresentation.ts` : SVG Netflix et Spotify, image Disney+ fournie.
Après un changement de ces fichiers, régénérer la vidéo et son affiche avec
`scripts/render-equitab-story.mjs` (Node compatible TypeScript, canvas, sharp et ffmpeg).

Le catalogue utilise les catégories du serveur et conserve les liens vers les
groupes. Les parts restent indicatives, calculées en cents pour un groupe complet.
Aucun propriétaire, membre, favori ou nombre de places disponibles n’est simulé
dans ces cartes de services.

Vérifications locales sans authentification réelle ni paiement :

- `node --experimental-strip-types --test scripts/test-service-presentation.mjs`
  (Node compatible avec l’effacement des types TypeScript).
- `/services?empty=1` : catalogue vide.
- `/services?edge=1` : nom long, service inconnu et prix/capacité manquants.
- `/login?status=1&noreset=1` : message de session fictif et récupération désactivée.

La source de l’animation est `scripts/render-equitab-story.mjs`. Sa régénération
nécessite `ffmpeg` et `@napi-rs/canvas` ; `EQUITAB_RENDER_MODULES` peut pointer vers
un dossier `node_modules` existant. Ces outils ne sont pas nécessaires à la lecture
de la vidéo ni au fonctionnement de l’application.

#### Extension de la collection aux parcours membres

La direction validée dans la capture utilisateur est intégrée aux pages réelles :
accueil, groupes, inscription, tableau de bord, abonnements, historique des
paiements, messagerie, profil, préférences et création de groupe. La connexion
70/30 et le catalogue restent dans cette même famille. Les pages légales et les
formulaires secondaires héritent de la navigation et de l’habillage communs.

`CollectionCard` sépare la scène décorative des informations et des actions.
Sur l’accueil, le montant est la **part actuelle** reçue du serveur ; dans les
groupes, c’est la **part estimée après arrivée**. Le catalogue de l’accueil ne
présente pas son ancien champ `pricePerMember` comme une part : cette donnée
correspond en réalité au prix total du service en dollars. Aucun favori de
démonstration ni information de confiance n’est ajouté aux données réelles.

Les détails de groupe et les confirmations utilisent un dialogue natif, avec
Échap, focus contenu et retour au déclencheur. Le formulaire Stripe n’est monté
qu’après demande explicite et réception du récapitulatif. Les appels existants
et règles métier côté serveur ne sont pas réécrits. L’écran de confirmation
de transaction ne fait pas partie de cette passe visuelle.

Scénarios de l’aperçu :

- `/?catalogonly=1` : catalogue sans groupe ; `/?empty=1` : collection vide.
- `/groups/service/netflix` : groupes ouverts et complet ; `?empty=1` : aucun groupe.
- `/dashboard/subscriptions` : cartes rejointes et partagées ; `?empty=1` : état vide.
- `/dashboard/payments` : payé, en attente et échoué ; filtres limités à la page affichée.
- `/dashboard/chat` : conversations et messages fictifs ; aucun envoi réel.
- `/dashboard/profile` : profil sans score, édition locale et annulation.
- `/dashboard/preferences` : rubriques et interrupteurs au clavier, sans sauvegarde.
- `/dashboard/groups/create` : étapes de création ; `?unverified=1` : vérification requise.

Tous les accès privés et récapitulatifs de paiement `/api/…` sont bloqués dans
l’aperçu, hormis les lectures des conversations fictives. Les formulaires,
redirections Stripe et mutations financières doivent être testés séparément
dans un environnement applicatif de test avant mise en ligne.

#### Identité EquitAb et administration

La marque utilise les fichiers originaux `public/Images/EquitabLogo.svg` et
`EquitabLogoblanc.svg`, sans recréation du symbole. Les accents suivent le vert
`#35AF7F` et le gris du logo ; les textes et actions utilisent un vert plus sombre
pour leur contraste. Les pages réelles n’affichent plus de bascule « Animations » :
les mouvements suivent automatiquement `prefers-reduced-motion`. La vidéo conserve
sa pause explicite et ses règles de lecture hors écran/mobile.

Les champs de connexion conservent l’autoremplissage du navigateur, avec une surface
blanche cohérente via `:autofill` et `:-webkit-autofill`. Le rendu exact de
l’autoremplissage natif reste à vérifier dans le navigateur utilisé en production.

Les 22 services du catalogue initial ont une palette identifiée dans
`resources/js/config/servicePresentation.ts`. Les marques disponibles sont servies
localement depuis `public/Images/services/`, avec un nom lisible en repli en cas
d’absence ou d’échec de chargement. Les pictogrammes monochromes proviennent de
[Simple Icons 15.18.0](https://github.com/simple-icons/simple-icons/tree/15.18.0/icons)
(Netflix, Spotify, YouTube, Apple Music, Tidal, Crunchyroll, Paramount+, NordVPN,
Envato, Google, Apple, Duolingo), et de
[Simple Icons 13.0.0](https://github.com/simple-icons/simple-icons/tree/13.0.0/icons)
(Nintendo et Amazon Prime). Google One et Apple One emploient la marque mère.
La licence CC0 du jeu est conservée dans `public/Images/services/LICENSE.txt` ;
elle n’accorde pas de droit sur les marques ni ne démontre un partenariat.
Les noms remplacent les logos non sourcés, sans symbole inventé.
Les cinq images fournies pour Disney+, CANAL+, Deezer, CyberGhost et Xbox Game Pass
sont prioritaires dans ce même dossier. Leurs noms et fichiers originaux sont
conservés ; leur cadrage est ajusté uniquement à l’affichage. La licence du jeu
Simple Icons ne couvre pas ces fichiers fournis séparément.
Les repères de marque ont été consultés chez
[Netflix](https://brand.netflix.com/en/assets/logos) et
[Spotify](https://developer.spotify.com/documentation/design).

Les six écrans Admin partagent maintenant la même navigation et une présentation
responsive : vue d’ensemble, utilisateurs, groupes, paiements, litiges, messagerie.
Les listes deviennent des fiches étiquetées sur petit écran ; la pagination utilise
les URL renvoyées par Laravel. Les confirmations utilisent le dialogue natif
(Échap, focus contenu, retour au déclencheur). La messagerie demande une vérification
des destinataires et du contenu avant l’envoi. Les routes, droits et règles de
facturation côté serveur restent inchangés. Un score absent n’est plus affiché à 0 %.

L’aperçu comprend `/admin`, `/admin/users`, `/admin/groups`, `/admin/payments`,
`/admin/disputes` et `/admin/messages`, toujours avec des données fictives.
`?empty=1` expose les états vides ; `?page=2` permet de vérifier la pagination
des listes. Aucun remboursement, envoi de courriel, suspension ou effacement de
compte n’est exécuté pendant ces vérifications.

#### Mon espace : surfaces blanches

La déclinaison membre utilise un fond blanc, des cartes blanches et le vert EquitAb
pour les actions. Les statistiques n’ont plus de fonds mauves ou multicolores.
Les abonnements et le choix de service emploient des logos sur fond blanc,
sans scènes illustrées ni dégradés. Les couleurs des alertes restent fonctionnelles.
Le catalogue public conserve les animations et les couleurs propres aux services.
L’aperçu `/dashboard?details=1` expose aussi les cartes compactes, un score et un
badge explicitement fictifs ; `/dashboard?empty=1` vérifie le premier usage.

### Cartes de test Stripe
| Carte | Résultat |
|---|---|
| `4242 4242 4242 4242` | Paiement réussi |
| `4000 0025 0000 3155` | 3DS requis |
| `4000 0000 0000 9995` | Fonds insuffisants |

### Flux de test complet
1. Créer un propriétaire → vérifier identité → configurer compte bancaire
2. Créer un groupe Netflix avec identifiants
3. Créer un membre → rejoindre le groupe → payer
4. Vérifier accès aux identifiants
5. Tester la dispute manuelle

---

## Licence

Propriétaire — tous droits réservés © 2026 Equitab Inc.

---

*Construit pour le marché canadien*
