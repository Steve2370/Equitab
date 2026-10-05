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
