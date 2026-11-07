FROM php:8.5.12-cli-alpine

COPY --from=composer:2.11.1 /usr/bin/composer /usr/bin/composer

WORKDIR /app
ENV APP_ENV=prod

COPY composer.json composer.lock ./
RUN composer install --no-autoloader --no-interaction --no-progress
COPY . .
RUN composer dump-autoload --optimize \
    && bin/console cache:warmup \
    && chown -R www-data:www-data var

USER www-data
EXPOSE 8001
CMD ["sh", "-c", "rm -f \"${DATABASE_PATH:-var/service-provider.sqlite}\" && bin/console doctrine:schema:create --quiet && exec php -S 0.0.0.0:8001 -t public"]
