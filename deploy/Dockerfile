# Pin these build arguments to reviewed immutable image digests in a release deployment.
ARG PHP_IMAGE=php:8.3-fpm-alpine
ARG COMPOSER_IMAGE=composer:2
FROM ${COMPOSER_IMAGE} AS composer
FROM ${PHP_IMAGE}
RUN apk add --no-cache libpq ca-certificates \
    && apk add --no-cache --virtual .build-deps $PHPIZE_DEPS postgresql-dev \
    && docker-php-ext-install pdo_mysql pdo_pgsql \
    && apk del .build-deps
COPY --from=composer /usr/bin/composer /usr/local/bin/composer
WORKDIR /srv/corevia
COPY . .
RUN composer install --no-dev --no-interaction --no-scripts --prefer-dist \
    && mkdir -p storage \
    && chown -R www-data:www-data storage \
    && chmod 700 storage
COPY deploy/php-production.ini /usr/local/etc/php/conf.d/corevia.ini
USER www-data
EXPOSE 9000
CMD ["php-fpm", "-F"]
