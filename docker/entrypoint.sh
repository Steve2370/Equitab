#!/bin/sh
set -e

# docker-compose.yml bind-mounts the whole project directory over /var/www
# at runtime ("volumes: - .:/var/www"), which shadows anything the image
# built at `docker build` time (composer/npm installs included). That's why
# a plain `git pull` + `docker compose up -d --build` used to leave the
# frontend (and, in edge cases, PHP deps) stuck on whatever was last built
# by hand on the host. Re-running both installs here, on every container
# start, means the running code always matches what's actually on disk —
# no manual npm/composer step to remember.

cd /var/www

# Le volume /var/www appartient à l'utilisateur de l'hôte, pas à root (qui
# exécute ce script dans le conteneur) : git refuse alors d'y opérer
# ("detected dubious ownership"). Sans effet de bord réel ici puisque
# personne ne commit depuis le conteneur, mais ça évite l'avertissement et
# tout futur outil qui dépendrait de git à l'intérieur du conteneur.
git config --global --add safe.directory /var/www

if [ -f composer.json ]; then
    composer install --no-interaction --optimize-autoloader
fi

if [ -f package.json ]; then
    npm ci
    npm run build
fi

chown -R www-data:www-data storage bootstrap/cache 2>/dev/null || true

exec "$@"
