FROM dunglas/frankenphp:1.3.6-php8.4-alpine

# ── OCI metadata (surfaced on Docker Hub and `docker inspect`) ──────────
LABEL org.opencontainers.image.title="Prismarr" \
      org.opencontainers.image.description="Self-hosted media stack dashboard — Radarr, Sonarr, Prowlarr, Jellyseerr, qBittorrent, TMDb in one Symfony UI." \
      org.opencontainers.image.licenses="AGPL-3.0-or-later" \
      org.opencontainers.image.source="https://github.com/Shoshuo/Prismarr" \
      org.opencontainers.image.url="https://github.com/Shoshuo/Prismarr" \
      org.opencontainers.image.documentation="https://github.com/Shoshuo/Prismarr#readme" \
      org.opencontainers.image.vendor="Prismarr"

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

# ── System libs + extensions ────────────────────────────────────────────
# Runtime libs stay; build-time deps (including git/zip used only by
# Composer) are purged after `composer install` further down.
RUN apk add --no-cache \
        bash \
        curl \
        unzip \
        icu-libs \
        libzip \
        oniguruma \
        sqlite-libs \
    && apk add --no-cache --virtual .build-deps \
        git \
        zip \
        icu-dev \
        libzip-dev \
        oniguruma-dev \
        linux-headers \
        sqlite-dev \
        $PHPIZE_DEPS \
    && docker-php-ext-install \
        pdo_sqlite \
        intl \
        zip \
        opcache \
        pcntl \
        sockets \
        mbstring

# ── Composer (pinned to avoid drift across rebuilds) ───────────────────
COPY --from=composer:2.8 /usr/bin/composer /usr/bin/composer

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

# Dependencies + assets. Once Composer finishes we purge the build-deps
# virtual group (git, zip, dev headers) — shaves ~10 MB off the final image.
RUN composer install --no-dev --optimize-autoloader --no-scripts --no-interaction \
    && php bin/console importmap:install --no-interaction \
    && apk del --purge .build-deps

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
