# syntax=docker/dockerfile:1

###########################################
# Stage 1: Composer dependencies
###########################################
FROM composer:2 AS composer

WORKDIR /app

COPY composer.json composer.lock ./
RUN composer install \
    --no-dev \
    --no-interaction \
    --no-scripts \
    --no-autoloader \
    --prefer-dist \
    --ignore-platform-reqs

###########################################
# Stage 2: Build frontend assets
###########################################
FROM node:22-alpine AS frontend

WORKDIR /app

# Copy package files and install
COPY package.json package-lock.json ./
RUN npm ci --ignore-scripts

# Copy source files needed for build
COPY vite.config.js ./
COPY resources ./resources
COPY public ./public
COPY app-modules ./app-modules

# Copy vendor for Filament theme CSS
COPY --from=composer /app/vendor ./vendor

RUN npm run build

###########################################
# Stage 3: Production image
###########################################
FROM serversideup/php:8.4-fpm-nginx AS production

LABEL org.opencontainers.image.title="Relaticle CRM"
LABEL org.opencontainers.image.description="Modern, open-source CRM platform"
LABEL org.opencontainers.image.source="https://github.com/Relaticle/relaticle"

# Switch to root to install dependencies
USER root

# Install required PHP extensions
RUN install-php-extensions intl exif gd imagick bcmath

# Install PostgreSQL client for health checks
RUN apt-get update \
    && apt-get install -y --no-install-recommends postgresql-client \
    && apt-get clean \
    && rm -rf /var/lib/apt/lists/*

# Switch back to www-data
USER www-data

WORKDIR /var/www/html

# Copy application source
COPY --chown=www-data:www-data . .

# Copy vendor from composer stage
COPY --chown=www-data:www-data --from=composer /app/vendor ./vendor

# Copy built frontend assets
COPY --chown=www-data:www-data --from=frontend /app/public/build ./public/build

# Generate optimized autoloader
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
