FROM php:8.3-cli-alpine AS vendor

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

WORKDIR /opt/ripple

COPY composer.json composer.lock ./
COPY src/ src/
COPY bin/ bin/

RUN composer install --no-dev --classmap-authoritative --no-interaction --no-scripts

FROM php:8.3-cli-alpine

RUN apk add --no-cache git

WORKDIR /opt/ripple

COPY --from=vendor /opt/ripple /opt/ripple

ENTRYPOINT ["php", "/opt/ripple/bin/ripple"]
