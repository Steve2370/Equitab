#!/bin/sh
set -e

# Entrypoint dédié aux conteneurs de fond (queue-worker, scheduler) qui
# partagent le même volume bind-mounté que le conteneur "app"
# (docker-compose.yml: ".:/var/www" sur les deux). Contrairement à
# docker/entrypoint.sh, on ne relance PAS "npm ci && npm run build" ici :
# ces conteneurs ne servent aucune page, et exécuter npm en parallèle dans
# plusieurs conteneurs sur le même node_modules/ bind-mounté peut corrompre
# l'installation. "composer install" reste nécessaire (autoload des jobs et
# commandes), mais Composer verrouille déjà son propre répertoire vendor/ —
# un démarrage concurrent avec le conteneur "app" attend simplement son tour
# au lieu de corrompre quoi que ce soit.

cd /var/www

git config --global --add safe.directory /var/www

if [ -f composer.json ]; then
    composer install --no-interaction --optimize-autoloader
fi

exec "$@"
