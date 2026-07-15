# syntax=docker/dockerfile:1.8

FROM node:22.22.0-bookworm-slim AS frontend-build
WORKDIR /src/frontend
COPY frontend/package.json frontend/package-lock.json ./
RUN npm ci --no-audit --no-fund
COPY frontend/ ./
RUN npm run build

FROM dunglas/frankenphp:1.12.4-php8.5.8-bookworm AS runtime
COPY --from=composer:2.10.2 /usr/bin/composer /usr/local/bin/composer
RUN install-php-extensions intl zip

WORKDIR /app
COPY backend/composer.json backend/composer.lock ./backend/
RUN cd backend && composer install \
    --no-dev \
    --no-interaction \
    --no-progress \
    --prefer-dist \
    --classmap-authoritative

COPY . ./
COPY --from=frontend-build /src/frontend/dist ./frontend/dist

RUN mkdir -p backend/logs backend/tmp/cache backend/tmp/rate-limits backend/tmp/sessions backend/tmp/tests \
    && chown -R www-data:www-data backend/logs backend/tmp

EXPOSE 8080 8081

CMD ["frankenphp", "run", "--config", "/app/infra/Caddyfile", "--adapter", "caddyfile"]
