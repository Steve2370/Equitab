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
