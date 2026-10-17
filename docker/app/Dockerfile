# syntax=docker/dockerfile:1.7
# One image for every role (ADR 0065): app (FrankenPHP, classic mode), queue (Horizon), scheduler, the `fsa` ops
# container and the backup daemon. The context is an allow-list (.dockerignore); the final stage carries no
# tests, docs, dev dependencies or composer, and stays within 1.0 GB uncompressed per architecture (E17).

FROM composer:2 AS vendor
WORKDIR /app
COPY composer.json composer.lock ./
COPY packages ./packages
RUN composer install --no-dev --no-scripts --no-autoloader --prefer-dist --no-interaction --ignore-platform-reqs

FROM node:24-bookworm-slim AS assets
WORKDIR /app
COPY package.json package-lock.json* ./
COPY packages ./packages
COPY resources ./resources
# form-schema-js imports the normative spec JSON (meta-schema, roles) at build time.
COPY spec ./spec
RUN npm ci --ignore-scripts || npm install --ignore-scripts
COPY vite.config.js ./
COPY public ./public
RUN npm run build

# Ops binaries (KTD8): static release assets pinned by SHA-256 per architecture; BuildKit refuses a mismatch.
# Refresh a tool by changing its URLs and both checksums together (sources: ADR 0065).
FROM scratch AS downloads-amd64
ADD --checksum=sha256:c158ecc55adb3afb01bbd3539ed3b997d20c26dbdc418080506cc1bf5243ff57 https://download.docker.com/linux/static/stable/x86_64/docker-29.9.1.tgz /docker.tgz
ADD --checksum=sha256:40343e21ca777173e69cff5dbafeb37c6f81f3b0d57d9e597f036e95eb63e76a https://github.com/docker/compose/releases/download/v5.6.0/docker-compose-linux-x86_64 /docker-compose
ADD --checksum=sha256:4629c757b7618056f8ddd7e2625ae9fdd94c0372a65049520bc7d9df9efc7f71 https://github.com/sigstore/cosign/releases/download/v3.1.3/cosign-linux-amd64 /cosign
ADD --checksum=sha256:cbe24006683f8eb669266162894b9a522a1af52f2665fbc63a4bb032ed26ac10 https://github.com/FiloSottile/age/releases/download/v1.3.2/age-v1.3.2-linux-amd64.tar.gz /age.tgz

FROM scratch AS downloads-arm64
ADD --checksum=sha256:3e93cc6d1a686456a3b18eefdde26467a837a7a2b257af22e27cba1dbc1b2018 https://download.docker.com/linux/static/stable/aarch64/docker-29.9.1.tgz /docker.tgz
ADD --checksum=sha256:733ec76717ceb59052a9609b9dadfb523b2df8eab57a54212872d10a58078ea2 https://github.com/docker/compose/releases/download/v5.6.0/docker-compose-linux-aarch64 /docker-compose
ADD --checksum=sha256:c5d324e091826b0d7a78eb16fef316450b4eb9aaec045611c08ba06f5e73220a https://github.com/sigstore/cosign/releases/download/v3.1.3/cosign-linux-arm64 /cosign
ADD --checksum=sha256:6b8dc4333c53a5a57c9e5834e3a48f92605d7154014cd07269ff3327db5d37f4 https://github.com/FiloSottile/age/releases/download/v1.3.2/age-v1.3.2-linux-arm64.tar.gz /age.tgz

# hadolint ignore=DL3006
FROM downloads-${TARGETARCH} AS downloads

FROM debian:bookworm-slim AS tools
# Apache-2.0 asks for the license (and NOTICE) next to redistributed binaries; age's ships in its archive.
ADD --checksum=sha256:2d81ea060825006fc8f3fe28aa5dc0ffeb80faf325b612c955229157b8c10dc0 https://raw.githubusercontent.com/docker/cli/v29.9.1/LICENSE /out/doc/docker-cli/LICENSE
ADD --checksum=sha256:a8c869fbda819afb8d80e0ac19bac52e766bc6c19cb38cf94f52d64c4be2aab6 https://raw.githubusercontent.com/docker/cli/v29.9.1/NOTICE /out/doc/docker-cli/NOTICE
ADD --checksum=sha256:58d1e17ffe5109a7ae296caafcadfdbe6a7d176f0bc4ab01e12a689b0499d8bd https://raw.githubusercontent.com/docker/compose/v5.6.0/LICENSE /out/doc/docker-compose/LICENSE
ADD --checksum=sha256:c71d239df91726fc519c6eb72d318ec65820627232b2f796219e87dcf35d0ab4 https://raw.githubusercontent.com/sigstore/cosign/v3.1.3/LICENSE /out/doc/cosign/LICENSE
COPY --from=downloads / /downloads/
RUN mkdir -p /out/bin /out/cli-plugins /out/doc/age \
 && tar -xzf /downloads/docker.tgz -C /out/bin --strip-components=1 docker/docker \
 && tar -xzf /downloads/age.tgz -C /out/bin --strip-components=1 age/age age/age-keygen age/age-plugin-batchpass \
 && tar -xzf /downloads/age.tgz -C /out/doc/age --strip-components=1 age/LICENSE \
 && install -m 0755 /downloads/cosign /out/bin/cosign \
 && install -m 0755 /downloads/docker-compose /out/cli-plugins/docker-compose \
 && chown -R root:root /out && chmod 0755 /out/bin/* && chmod -R a+rX /out/doc

# PHP runtime: FrankenPHP with the app's extensions, qpdf, curl (healthchecks) and the PGDG Postgres 16 client
# (E13: pg_dump must match the server major; bookworm ships 15). The PHP base image keeps its build toolchain
# (compilers, binutils, headers: ~170 MB); it is removed here, and the `runtime` stage flattens the result so
# that the removal actually shrinks the image.
FROM dunglas/frankenphp:1-php8.4-bookworm AS runtime-build
# PGDG signing key, fingerprint B97B 0AFC AA1A 47F0 44F2 44A0 7FCC 7D46 ACCC 4CF8 (postgresql.org/download/linux/debian).
ADD --checksum=sha256:0144068502a1eddd2a0280ede10ef607d1ec592ce819940991203941564e8e76 https://www.postgresql.org/media/keys/ACCC4CF8.asc /usr/share/postgresql-common/pgdg/apt.postgresql.org.asc
# DL4006: the library scan pipes on purpose without pipefail (dpkg-query fails for paths no package owns).
# hadolint ignore=DL3008,DL4006,SC2016
RUN <<'SH'
set -eu
install-php-extensions pdo_pgsql intl gd zip bcmath exif pcntl opcache redis

# PGDG through a deb822 source without an architecture field, so the same file serves amd64 and arm64.
chmod 0644 /usr/share/postgresql-common/pgdg/apt.postgresql.org.asc
. /etc/os-release
printf 'Types: deb\nURIs: https://apt.postgresql.org/pub/repos/apt\nSuites: %s-pgdg\nComponents: main\nSigned-By: /usr/share/postgresql-common/pgdg/apt.postgresql.org.asc\n' \
  "$VERSION_CODENAME" > /etc/apt/sources.list.d/pgdg.sources
apt-get update
apt-get install -y --no-install-recommends qpdf curl media-types postgresql-client-16

# The app listens on 8080: without the file capability FrankenPHP also starts with every capability dropped.
setcap -r /usr/local/bin/frankenphp
# /var/www/html is world-writable in the PHP base image; the code stays root-owned and read-only to www-data.
chown root:root /var/www/html
chmod 0755 /var/www/html

# Keep Debian's required/important base, the runtime packages above (media-types gives Caddy /etc/mime.types)
# and every library that PHP, its extensions and FrankenPHP in /usr/local link; purge the rest.
apt-mark auto '.*' > /dev/null
apt-mark manual ca-certificates curl media-types qpdf postgresql-client-16 \
  $(dpkg-query -Wf '${Package} ${Priority}\n' | awk '$2 == "required" || $2 == "important" { print $1 }') > /dev/null
find /usr/local -type f \( -perm -u+x -o -name '*.so*' \) -exec ldd {} + 2>/dev/null \
  | awk '/=>/ { so = $(NF-1); if (index(so, "/usr/local/") == 1) next; gsub("^/(usr/)?", "", so); printf "*%s\n", so }' \
  | sort -u | xargs -r dpkg-query --search 2>/dev/null | cut -d: -f1 | sort -u | xargs -r apt-mark manual > /dev/null
apt-get purge -y --auto-remove -o APT::AutoRemove::RecommendsImportant=false
rm -rf /var/lib/apt/lists/* /usr/src/* /usr/local/include/php /usr/local/lib/php/build /usr/local/bin/php-cgi \
  /usr/local/bin/phpdbg /usr/local/bin/phpize /usr/local/bin/php-config /usr/local/bin/install-php-extensions

# Fail the build if the purge took an extension or a shared library with it.
php -r 'foreach (["pdo_pgsql", "intl", "gd", "zip", "bcmath", "exif", "pcntl", "Zend OPcache", "redis"] as $e) { extension_loaded($e) || exit(1); }'
missing="$(find /usr/local /usr/lib/postgresql /usr/bin/qpdf /usr/bin/curl -type f -exec ldd {} + 2>/dev/null | grep 'not found' || true)"
if [ -n "$missing" ]; then
  echo "missing shared libraries: $missing" >&2
  exit 1
fi
frankenphp version
pg_dump --version
qpdf --version
curl --version
SH

FROM scratch AS runtime
# hadolint ignore=DL3067
COPY --from=runtime-build / /
ENV PATH=/usr/local/sbin:/usr/local/bin:/usr/sbin:/usr/bin:/sbin:/bin \
    PHP_INI_DIR=/usr/local/etc/php \
    GODEBUG=cgocheck=0 \
    XDG_CONFIG_HOME=/tmp/xdg-config \
    XDG_DATA_HOME=/tmp/xdg-data \
    SERVER_NAME=:8080 \
    APP_ENV=production \
    LOG_CHANNEL=stderr \
    LOG_STDERR_FORMATTER=App\\Support\\Logging\\JsonLogFormatter
WORKDIR /var/www/html

# Application tree: composer exists only in this stage. Classmap, package manifest and Filament assets are built
# here and copied into the final stage as one tree.
FROM runtime AS build
COPY --from=composer:2 /usr/bin/composer /usr/bin/composer
COPY artisan composer.json composer.lock LICENSE ./
COPY app ./app
COPY bootstrap ./bootstrap
COPY config ./config
COPY database ./database
COPY lang ./lang
COPY public ./public
COPY resources/views ./resources/views
COPY resources/fonts ./resources/fonts
COPY routes ./routes
COPY storage ./storage
# SpecPaths.php and RoleOptions read the normative spec at runtime; TemplateCatalog reads spec/templates.
COPY spec/LICENSE ./spec/LICENSE
COPY spec/form-definition/1.0 ./spec/form-definition/1.0
COPY spec/templates ./spec/templates
# fsa validates release manifests against the schema (ManifestVerifier) and install.sh copies the shim from here.
COPY distribution/manifest.schema.json distribution/shim.sh ./distribution/
COPY packages/form-schema-php/composer.json packages/form-schema-php/LICENSE ./packages/form-schema-php/
COPY packages/form-schema-php/src ./packages/form-schema-php/src
COPY --from=vendor /app/vendor ./vendor
COPY --from=assets /app/public/build ./public/build
COPY --from=assets /app/public/app ./public/app
COPY --from=assets /app/public/form-preview ./public/form-preview
RUN composer dump-autoload --optimize --no-dev --classmap-authoritative \
 && php artisan filament:assets \
 && chown -R www-data:www-data storage bootstrap/cache

FROM runtime AS app
COPY docker/app/php.ini /usr/local/etc/php/conf.d/zz-app.ini
COPY docker/app/Caddyfile /etc/frankenphp/Caddyfile
COPY --from=tools /out/bin/ /usr/local/bin/
COPY --from=tools /out/cli-plugins/ /usr/local/lib/docker/cli-plugins/
COPY --from=tools /out/doc/ /usr/share/doc/
COPY --from=build /var/www/html /var/www/html
COPY --chmod=0755 docker/app/entrypoint.sh /usr/local/bin/app-entrypoint
COPY --chmod=0755 docker/app/fsa /usr/local/bin/fsa
# Build flavor (R58, ADR 0070): only the release harness builds with FSA_BUILD=harness, which lets fsa verify
# releases with a local test key (FSA_TRUST_KEY). Release builds keep the default; a file, not ENV, so that
# `docker run -e` cannot change it.
ARG FSA_BUILD=production
RUN case "${FSA_BUILD}" in production | harness) ;; *) echo "FSA_BUILD must be production or harness" >&2; exit 1 ;; esac \
 && printf '%s\n' "${FSA_BUILD}" > /var/www/html/distribution/.build-flavor
# Version metadata last, so a version bump rebuilds no layer above (KTD9, R1).
ARG APP_VERSION=0.0.0-dev
ARG APP_REVISION=
LABEL org.opencontainers.image.title="form-schema-app" \
      org.opencontainers.image.description="Self-hostable form schema application: app, queue, scheduler, fsa ops and backup roles" \
      org.opencontainers.image.version="${APP_VERSION}" \
      org.opencontainers.image.revision="${APP_REVISION}" \
      org.opencontainers.image.source="https://github.com/beyerenrico/form-schema-app" \
      org.opencontainers.image.url="https://github.com/beyerenrico/form-schema-app" \
      org.opencontainers.image.licenses="AGPL-3.0-only"
ENV APP_VERSION="${APP_VERSION}" \
    APP_REVISION="${APP_REVISION}"
# Debian's fixed www-data (33:33), the owner of storage/ and bootstrap/cache.
# hadolint ignore=DL3066
USER www-data
EXPOSE 8080
ENTRYPOINT ["app-entrypoint"]
CMD ["frankenphp", "run", "--config", "/etc/frankenphp/Caddyfile"]
