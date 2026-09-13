# Entorno de desarrollo: PHP 8.5 + Node 22 + pnpm (versiones que exige upstream).
FROM php:8.5-cli-bookworm

ARG USERNAME=vscode
ARG USER_UID=1000
ARG USER_GID=1000

COPY --from=mlocati/php-extension-installer /usr/bin/install-php-extensions /usr/local/bin/

RUN install-php-extensions bcmath exif gd imagick intl pcntl pdo_pgsql pgsql redis sockets zip @composer

COPY php.ini /usr/local/etc/php/conf.d/zz-devcontainer.ini

RUN curl -fsSL https://deb.nodesource.com/setup_22.x | bash - \
    && apt-get install -y --no-install-recommends \
        git less nodejs postgresql-client sudo unzip \
    && apt-get clean \
    && rm -rf /var/lib/apt/lists/*

# pnpm vía corepack: usa la versión fijada en "packageManager" de package.json.
ENV COREPACK_ENABLE_DOWNLOAD_PROMPT=0
RUN corepack enable

RUN groupadd --gid "$USER_GID" "$USERNAME" \
    && useradd --uid "$USER_UID" --gid "$USER_GID" -m -s /bin/bash "$USERNAME" \
    && echo "$USERNAME ALL=(root) NOPASSWD:ALL" > "/etc/sudoers.d/$USERNAME" \
    && chmod 0440 "/etc/sudoers.d/$USERNAME"

USER $USERNAME
