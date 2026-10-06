#!/bin/sh
set -e

# Entrypoint dédié aux conteneurs de fond (queue-worker, scheduler) qui
# partagent le même volume bind-mounté que le conteneur "app"
# (docker-compose.yml: ".:/var/www" sur les deux). Contrairement à
# docker/entrypoint.sh, on ne relance PAS "npm ci && npm run build" ici :
# ces conteneurs ne servent aucune page. Seul app prépare vendor/node_modules :
# depends_on: service_healthy garantit la fin de sa préparation avant démarrage.
# Composer n'est pas un verrou inter-conteneurs. Ne pas lancer de worker manuel
# avant app, ni modifier le checkout partagé tant qu'un worker tourne.

cd /var/www

if [ ! -r vendor/autoload.php ]; then
    echo "Dépendances absentes : démarrer app et attendre son état healthy." >&2
    exit 1
fi

exec "$@"
