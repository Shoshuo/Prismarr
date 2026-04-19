FROM dunglas/frankenphp:1.3.6-php8.4-alpine

# ── s6-overlay (multi-process init system) ──────────────────────────────
ARG S6_OVERLAY_VERSION=3.2.0.2
ARG TARGETARCH

# Download s6-overlay (noarch + arch-specific)
ADD https://github.com/just-containers/s6-overlay/releases/download/v${S6_OVERLAY_VERSION}/s6-overlay-noarch.tar.xz /tmp/
RUN if [ "$TARGETARCH" = "arm64" ]; then \
      wget -q -O /tmp/s6-overlay-arch.tar.xz https://github.com/just-containers/s6-overlay/releases/download/v${S6_OVERLAY_VERSION}/s6-overlay-aarch64.tar.xz; \
    else \
      wget -q -O /tmp/s6-overlay-arch.tar.xz https://github.com/just-containers/s6-overlay/releases/download/v${S6_OVERLAY_VERSION}/s6-overlay-x86_64.tar.xz; \
    fi && \
    tar -C / -Jxpf /tmp/s6-overlay-noarch.tar.xz && \
    tar -C / -Jxpf /tmp/s6-overlay-arch.tar.xz && \
    rm /tmp/s6-overlay-*.tar.xz

# ── System extensions ───────────────────────────────────────────────────
RUN apk add --no-cache \
    bash \
    curl \
    git \
    unzip \
    zip \
    icu-dev \
    libzip-dev \
    oniguruma-dev \
    linux-headers \
    sqlite-dev \
    $PHPIZE_DEPS

# ── PHP extensions (no Redis, no pdo_mysql) ─────────────────────────────
RUN docker-php-ext-install \
    pdo_sqlite \
    intl \
    zip \
    opcache \
    pcntl \
    sockets \
    mbstring

# ── Composer ────────────────────────────────────────────────────────────
COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

# ── PHP config ──────────────────────────────────────────────────────────
COPY docker/frankenphp/php.ini $PHP_INI_DIR/conf.d/prismarr.ini

# ── Caddyfile (HTTP + embedded Mercure) ─────────────────────────────────
COPY docker/frankenphp/Caddyfile /etc/caddy/Caddyfile
RUN chmod 0644 /etc/caddy/Caddyfile && \
    chown -R www-data:www-data /data/caddy /config/caddy

# ── s6 services ─────────────────────────────────────────────────────────
COPY docker/frankenphp/s6/ /etc/s6-overlay/s6-rc.d/

# ── Init script (secret generation + DB schema) ─────────────────────────
COPY docker/frankenphp/init.sh /usr/local/bin/prismarr-init
RUN chmod +x /usr/local/bin/prismarr-init

# ── Symfony code ────────────────────────────────────────────────────────
WORKDIR /var/www/html
COPY symfony/ .

# Dependencies + assets
RUN composer install --no-dev --optimize-autoloader --no-scripts --no-interaction
RUN php bin/console importmap:install --no-interaction

# Permissions
RUN mkdir -p var/data var/cache var/log && \
    chown -R www-data:www-data /var/www/html

# ── Healthcheck ─────────────────────────────────────────────────────────
HEALTHCHECK --interval=30s --timeout=5s --retries=3 \
    CMD curl -fsS http://localhost:7070/api/health || exit 1

EXPOSE 7070

# s6-overlay must run as root (PID 1 requirement). Individual services
# (frankenphp, messenger-worker) drop privileges to www-data via
# `s6-setuidgid` in their respective run scripts under s6/*/run.
ENTRYPOINT ["/init"]
