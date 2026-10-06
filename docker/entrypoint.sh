#!/bin/sh
set -e

# docker-compose.yml bind-mounts the whole project directory over /var/www
# at runtime ("volumes: - .:/var/www"), which shadows anything the image
# built at `docker build` time (composer/npm installs included). That's why
# a plain `git pull` + `docker compose up -d --build` used to leave the
# frontend (and, in edge cases, PHP deps) stuck on whatever was last built
# by hand on the host. Only app prepares this shared runtime; workers wait
# for its healthcheck in Compose and never install dependencies concurrently.
# Stop app/workers/scheduler before changing the shared checkout during release.

cd /var/www

# Le volume /var/www appartient à l'utilisateur de l'hôte, pas à root (qui
# exécute ce script dans le conteneur) : git refuse alors d'y opérer
# ("detected dubious ownership"). Sans effet de bord réel ici puisque
# personne ne commit depuis le conteneur, mais ça évite l'avertissement et
# tout futur outil qui dépendrait de git à l'intérieur du conteneur.
git config --global --add safe.directory /var/www

if [ -f composer.json ]; then
    if [ "${INSTALL_DEV_DEPENDENCIES:-0}" = "1" ]; then
        composer install --no-interaction --no-plugins --optimize-autoloader
    else
        composer install --no-dev --no-interaction --no-plugins --optimize-autoloader
    fi
fi

if [ -f package.json ]; then
    # Build tools remain necessary while the whole checkout is bind-mounted.
    npm ci --ignore-scripts --no-audit --no-fund
    npm run build
fi

chown -R www-data:www-data storage bootstrap/cache
# The scheduler regenerates this tracked file; do not grant write access to
# the entire public directory when dropping its root privileges.
if [ -f public/sitemap.xml ]; then
    chown www-data:www-data public/sitemap.xml
fi

exec "$@"
