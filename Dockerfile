# syntax=docker/dockerfile:1

###########################################
# Stage 1: Build frontend assets
###########################################
FROM node:22-alpine AS frontend

WORKDIR /app

COPY package.json package-lock.json ./
RUN npm ci --ignore-scripts

COPY vite.config.js ./
COPY resources ./resources
COPY public ./public

RUN npm run build

###########################################
# Stage 2: Production image
###########################################
FROM serversideup/php:8.4-fpm-nginx AS production

LABEL org.opencontainers.image.title="Relaticle CRM"
LABEL org.opencontainers.image.description="Modern, open-source CRM platform"
LABEL org.opencontainers.image.source="https://github.com/Relaticle/relaticle"

# Switch to root to install dependencies
USER root

# Install PostgreSQL client for health checks
RUN apt-get update \
    && apt-get install -y --no-install-recommends postgresql-client \
    && apt-get clean \
    && rm -rf /var/lib/apt/lists/*

# Switch back to www-data
USER www-data

WORKDIR /var/www/html

# Copy composer files first for better caching
COPY --chown=www-data:www-data composer.json composer.lock ./

# Install PHP dependencies
RUN composer install \
    --no-dev \
    --no-interaction \
    --no-scripts \
    --no-autoloader \
    --prefer-dist

# Copy application source
COPY --chown=www-data:www-data . .

# Copy built frontend assets
COPY --chown=www-data:www-data --from=frontend /app/public/build ./public/build

# Generate optimized autoloader and run post-install scripts
RUN composer dump-autoload --optimize --no-dev

# Create storage directories
RUN mkdir -p \
    storage/app/public \
    storage/framework/cache/data \
    storage/framework/sessions \
    storage/framework/views \
    storage/logs \
    bootstrap/cache

# Default environment for serversideup/php Laravel automations
ENV AUTORUN_ENABLED=true
ENV AUTORUN_LARAVEL_STORAGE_LINK=true
ENV AUTORUN_LARAVEL_MIGRATION=true
ENV AUTORUN_LARAVEL_MIGRATION_ISOLATION=true
ENV AUTORUN_LARAVEL_CONFIG_CACHE=true
ENV AUTORUN_LARAVEL_ROUTE_CACHE=true
ENV AUTORUN_LARAVEL_VIEW_CACHE=true
ENV AUTORUN_LARAVEL_EVENT_CACHE=true
ENV AUTORUN_LARAVEL_OPTIMIZE=false
ENV PHP_OPCACHE_ENABLE=1
ENV SSL_MODE=off

EXPOSE 8080
