# syntax=docker/dockerfile:1
#
# NetOps — image PHP 8.3-FPM (Debian trixie) avec oci8 (Oracle Instant Client),
# pdo_pgsql, pdo_sqlite, soap, intl, opcache, zip.
#
# Cibles :
#   base   : PHP + extensions, sans le code (sert aussi à vérifier `php -m`)
#   prod   : dépendances sans dev, cache Symfony préchauffé, assets compilés
#   nginx  : nginx non root servant public/ et relayant PHP vers php-fpm
#   dev    : dépendances de dev (PHPUnit, PHPStan, fixtures...)
#   test   : dev + APP_ENV=test, lance la suite PHPUnit
#
# Instant Client est publié pour linux/amd64 (et arm64 sous d'autres URL) :
# cette image cible linux/amd64.

ARG PHP_VERSION=8.3

FROM composer:2 AS composer

# ---------------------------------------------------------------------------
FROM php:${PHP_VERSION}-fpm-trixie AS base

# Oracle Instant Client 23.26 (connexion aux bases 19c et suivantes, dont XE 21).
# Les sommes SHA-256 sont celles publiées sur la page de téléchargement d'Oracle.
ARG INSTANTCLIENT_VERSION=23.26.4.0.0
ARG INSTANTCLIENT_DIR=2326400
ARG INSTANTCLIENT_BASIC_SHA256=cab59aa3b411b3b50898e456fa9aabfe48d67e25cbc1bb23303800390ce7de26
ARG INSTANTCLIENT_SDK_SHA256=75b7b72c378693eca8f3e0502f2d035612baec43be1e75760839cc87c437fb01
# oci8 3.3.x = branche PECL pour PHP 8.3 (3.4 vise PHP 8.4).
ARG OCI8_VERSION=3.3.0

ENV ORACLE_HOME=/opt/oracle/instantclient \
    LD_LIBRARY_PATH=/opt/oracle/instantclient \
    NLS_LANG=AMERICAN_AMERICA.AL32UTF8 \
    TZ=UTC

SHELL ["/bin/bash", "-Eeuo", "pipefail", "-c"]

# savedAptMark est volontairement découpé en mots ; versions apt non figées (paquets Debian stables).
# hadolint ignore=DL3008,SC2086
RUN apt-get update; \
    # Bibliothèques d'exécution conservées dans l'image.
    apt-get install -y --no-install-recommends ca-certificates libaio1t64 libfcgi-bin; \
    savedAptMark="$(apt-mark showmanual)"; \
    # Paquets de compilation, retirés en fin d'étape.
    apt-get install -y --no-install-recommends curl unzip libicu-dev libpq-dev libxml2-dev libzip-dev; \
    \
    # --- Oracle Instant Client (basic + sdk) -------------------------------
    base_url="https://download.oracle.com/otn_software/linux/instantclient/${INSTANTCLIENT_DIR}"; \
    curl -fsSL -o /tmp/ic-basic.zip "${base_url}/instantclient-basic-linux.x64-${INSTANTCLIENT_VERSION}.zip"; \
    curl -fsSL -o /tmp/ic-sdk.zip "${base_url}/instantclient-sdk-linux.x64-${INSTANTCLIENT_VERSION}.zip"; \
    echo "${INSTANTCLIENT_BASIC_SHA256}  /tmp/ic-basic.zip" | sha256sum -c -; \
    echo "${INSTANTCLIENT_SDK_SHA256}  /tmp/ic-sdk.zip" | sha256sum -c -; \
    mkdir -p /opt/oracle; \
    unzip -qo /tmp/ic-basic.zip -d /opt/oracle; \
    unzip -qo /tmp/ic-sdk.zip -d /opt/oracle; \
    rm -rf /tmp/ic-basic.zip /tmp/ic-sdk.zip /opt/oracle/META-INF; \
    mv /opt/oracle/instantclient_* "${ORACLE_HOME}"; \
    # Debian trixie renomme libaio.so.1 en libaio.so.1t64 (transition time_t 64 bits).
    libaio="$(ldconfig -p | awk '/libaio\.so\.1t64/ { print $NF; exit }')"; \
    ln -sf "${libaio}" "${libaio%t64}"; \
    echo "${ORACLE_HOME}" > /etc/ld.so.conf.d/oracle-instantclient.conf; \
    ldconfig; \
    \
    # --- Extensions PHP ----------------------------------------------------
    docker-php-ext-install -j"$(nproc)" intl opcache pdo_pgsql soap zip; \
    echo "instantclient,${ORACLE_HOME}" | pecl install "oci8-${OCI8_VERSION}"; \
    docker-php-ext-enable oci8; \
    rm -rf /tmp/pear ~/.pearrc "${ORACLE_HOME}"/sdk "${ORACLE_HOME}"/*.jar; \
    \
    # --- Nettoyage : on ne garde que les bibliothèques réellement liées ----
    apt-mark auto '.*' > /dev/null; \
    apt-mark manual ${savedAptMark} > /dev/null; \
    find /usr/local -type f -executable -exec ldd '{}' ';' 2>/dev/null \
      | awk '/=>/ { so = $(NF-1); if (index(so, "/usr/local/") == 1 || index(so, "/opt/") == 1) { next }; gsub("^/(usr/)?", "", so); printf "*%s\n", so }' \
      | sort -u \
      | { xargs -r dpkg-query --search 2>/dev/null || true; } \
      | awk -F: '!/^diversion/ { print $1 }' | sort -u | xargs -r apt-mark manual > /dev/null; \
    apt-get purge -y --auto-remove -o APT::AutoRemove::RecommendsImportant=false; \
    rm -rf /var/lib/apt/lists/*; \
    \
    # Échoue tôt si une extension attendue manque.
    for ext in oci8 pdo_pgsql pdo_sqlite soap intl 'Zend OPcache' zip; do \
      php -m | grep -qx "${ext}" || { echo "extension manquante : ${ext}" >&2; exit 1; }; \
    done

RUN cp "${PHP_INI_DIR}/php.ini-production" "${PHP_INI_DIR}/php.ini"

# Configuration par variables d'environnement uniquement : Symfony Runtime ne
# cherche pas de fichier .env dans l'image (bin/console, public/index.php).
ENV APP_RUNTIME_OPTIONS='{"disable_dotenv":true}'

COPY docker/php/php.ini "${PHP_INI_DIR}/conf.d/zz-netops.ini"
COPY docker/php/php-fpm.conf /usr/local/etc/php-fpm.d/zz-netops.conf
COPY --chmod=0755 docker/php/entrypoint.sh /usr/local/bin/netops-entrypoint
COPY --chmod=0755 docker/php/healthcheck.sh /usr/local/bin/netops-healthcheck
COPY --chmod=0755 docker/php/run-tests.sh /usr/local/bin/netops-run-tests
COPY --chmod=0755 docker/php/wait-for-db.sh /usr/local/bin/netops-wait-db

WORKDIR /srv/app

# ---------------------------------------------------------------------------
# Dépendances Composer (couche mise en cache tant que composer.lock ne change pas).
FROM base AS vendor-prod
COPY --from=composer /usr/bin/composer /usr/local/bin/composer
ENV COMPOSER_ALLOW_SUPERUSER=1 COMPOSER_CACHE_DIR=/tmp/composer-cache
COPY composer.json composer.lock ./
RUN --mount=type=cache,target=/tmp/composer-cache \
    composer install --no-dev --no-scripts --no-autoloader --prefer-dist --no-progress --no-interaction

FROM vendor-prod AS vendor-dev
RUN --mount=type=cache,target=/tmp/composer-cache \
    composer install --no-scripts --no-autoloader --prefer-dist --no-progress --no-interaction

# ---------------------------------------------------------------------------
FROM vendor-prod AS build-prod
ENV APP_ENV=prod APP_DEBUG=0
# Le cache prod fige les métadonnées Doctrine, dont la stratégie d'identifiant qui dépend de la
# plateforme (SEQUENCE sur Oracle, IDENTITY sur PostgreSQL et SQLite) : il est préchauffé pour la
# base visée par l'image. URL factice, aucune connexion n'est ouverte. Pour PostgreSQL :
#   --build-arg CACHE_WARMUP_DATABASE_URL='postgresql://localhost/netops?serverVersion=17'
ARG CACHE_WARMUP_DATABASE_URL="oci8://localhost:1521?servicename=XEPDB1"
COPY . .
RUN export DATABASE_URL="${CACHE_WARMUP_DATABASE_URL}"; \
    composer dump-autoload --no-dev --classmap-authoritative --no-interaction; \
    # auto-scripts Symfony : cache:clear, assets:install, importmap:install.
    composer run-script --no-dev post-install-cmd --no-interaction; \
    if php bin/console list --raw | grep -q '^asset-map:compile '; then php bin/console asset-map:compile; fi; \
    php bin/console cache:warmup; \
    rm -rf var/log/*

FROM base AS prod
ENV APP_ENV=prod APP_DEBUG=0
COPY --from=build-prod /srv/app /srv/app
RUN mkdir -p var/cache var/log; chown -R www-data:www-data var
USER www-data
EXPOSE 9000
HEALTHCHECK --interval=10s --timeout=5s --start-period=60s --retries=5 CMD ["netops-healthcheck"]
ENTRYPOINT ["netops-entrypoint"]
CMD ["php-fpm"]

# ---------------------------------------------------------------------------
FROM nginxinc/nginx-unprivileged:1.30-alpine AS nginx
# Adresse de php-fpm, substituée dans le gabarit au démarrage (envsubst).
ENV PHP_FPM_UPSTREAM=app:9000
COPY docker/nginx/default.conf.template /etc/nginx/templates/default.conf.template
COPY --from=build-prod /srv/app/public /srv/app/public
HEALTHCHECK --interval=10s --timeout=3s --retries=5 CMD ["wget", "-q", "-O", "/dev/null", "http://127.0.0.1:8080/nginx-health"]

# ---------------------------------------------------------------------------
FROM vendor-dev AS dev
ENV APP_ENV=dev
COPY docker/php/php-dev.ini "${PHP_INI_DIR}/conf.d/zz-netops-dev.ini"
COPY . .
RUN composer dump-autoload --no-interaction; \
    if php bin/console list --raw | grep -q '^importmap:install '; then php bin/console importmap:install; fi; \
    rm -rf var; mkdir -p var/cache var/log .phpunit.cache; chown -R www-data:www-data var .phpunit.cache
USER www-data
EXPOSE 9000
HEALTHCHECK --interval=10s --timeout=5s --start-period=60s --retries=5 CMD ["netops-healthcheck"]
ENTRYPOINT ["netops-entrypoint"]
CMD ["php-fpm"]

FROM dev AS test
ENV APP_ENV=test APP_DEBUG=1
HEALTHCHECK NONE
CMD ["netops-run-tests"]
