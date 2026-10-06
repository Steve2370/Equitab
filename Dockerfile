FROM php:8.4-fpm

RUN apt-get update && apt-get install -y \
    git curl libpq-dev libzip-dev libsqlite3-dev zip unzip \
    && docker-php-ext-install pdo pdo_pgsql pdo_sqlite zip pcntl \
    && curl -fsSL https://deb.nodesource.com/setup_22.x | bash - \
    && apt-get install -y nodejs \
    && rm -rf /var/lib/apt/lists/*
# pdo_sqlite est nécessaire pour exécuter la suite de tests : phpunit.xml
# force DB_CONNECTION=sqlite / DB_DATABASE=:memory: pendant les tests
# (base isolée, jamais la vraie base Postgres) — sans ce driver,
# "php artisan test" échoue avec "could not find driver".

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

# Production excludes PHP development tools. The local compose opts in for tests.
ARG INSTALL_DEV_DEPENDENCIES=0
ENV INSTALL_DEV_DEPENDENCIES=${INSTALL_DEV_DEPENDENCIES}

WORKDIR /var/www
COPY . .

RUN if [ "$INSTALL_DEV_DEPENDENCIES" = "1" ]; then \
        composer install --no-interaction --no-scripts --no-plugins --optimize-autoloader; \
    else \
        composer install --no-dev --no-interaction --no-scripts --no-plugins --optimize-autoloader; \
    fi
RUN npm ci --ignore-scripts --no-audit --no-fund
RUN npm run build
RUN chown -R www-data:www-data /var/www/storage /var/www/bootstrap/cache

RUN chmod +x docker/entrypoint.sh docker/entrypoint-worker.sh

ENTRYPOINT ["docker/entrypoint.sh"]
CMD ["php-fpm"]
