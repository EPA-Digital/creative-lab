# syntax=docker/dockerfile:1

# --- Stage: vendor (Composer install, sin dev deps) -----------------------
FROM composer:2 AS vendor
WORKDIR /app
COPY composer.json composer.lock ./
RUN composer install \
    --no-dev \
    --no-scripts \
    --no-autoloader \
    --ignore-platform-reqs
COPY . .
RUN composer dump-autoload --optimize --no-dev

# --- Stage: assets (Vite build) -------------------------------------------
# resources/js/app.js importa "../../vendor/tightenco/ziggy" (Ziggy publica
# su helper de rutas ahí, vía composer, no vía npm) -- sin ese paquete
# presente, vite build revienta con "Could not resolve".
FROM node:22-slim AS assets
WORKDIR /app
COPY package.json package-lock.json ./
RUN npm ci
COPY resources/ resources/
COPY vite.config.js tailwind.config.js postcss.config.js ./
COPY public/ public/
COPY --from=vendor /app/vendor/tightenco/ziggy vendor/tightenco/ziggy
RUN npm run build

# --- Stage final: php-fpm + nginx, un solo contenedor, un solo puerto -----
FROM php:8.4-fpm-alpine

RUN apk add --no-cache nginx supervisor icu-dev libzip-dev oniguruma-dev \
    && docker-php-ext-install pdo_mysql mbstring bcmath opcache intl \
    && rm -rf /var/cache/apk/*

WORKDIR /var/www/html

COPY --from=vendor /app /var/www/html
COPY --from=assets /app/public/build /var/www/html/public/build

RUN mkdir -p storage/framework/{cache,sessions,views} storage/logs bootstrap/cache public/creative-images \
    && chown -R www-data:www-data storage bootstrap/cache public/creative-images \
    && mkdir -p /var/lib/nginx/tmp/client_body \
    && chown -R www-data:www-data /var/lib/nginx

COPY docker/nginx.conf /etc/nginx/nginx.conf
COPY docker/supervisord.conf /etc/supervisor.d/app.ini
COPY docker/start.sh /start.sh
RUN chmod +x /start.sh

ENV APP_ENV=production
ENV LOG_CHANNEL=stderr
ENV PORT=8080

EXPOSE 8080
ENTRYPOINT ["/start.sh"]
