# Test runner with a coverage driver (pcov), used locally and mirroring the CI php-tests job.
FROM php:8.4-cli-bookworm
COPY --from=mlocati/php-extension-installer:2 /usr/bin/install-php-extensions /usr/local/bin/
# PGDG's Postgres 16 client for the backup tests (pg_dump must match the server major; bookworm ships 15). Signing key
# fingerprint B97B 0AFC AA1A 47F0 44F2 44A0 7FCC 7D46 ACCC 4CF8, as in docker/app/Dockerfile.
ADD --checksum=sha256:0144068502a1eddd2a0280ede10ef607d1ec592ce819940991203941564e8e76 https://www.postgresql.org/media/keys/ACCC4CF8.asc /usr/share/postgresql-common/pgdg/apt.postgresql.org.asc
# hadolint ignore=DL3008
RUN install-php-extensions pdo_pgsql intl gd zip bcmath exif pcntl pcov \
 && chmod 0644 /usr/share/postgresql-common/pgdg/apt.postgresql.org.asc \
 && printf 'Types: deb\nURIs: https://apt.postgresql.org/pub/repos/apt\nSuites: bookworm-pgdg\nComponents: main\nSigned-By: /usr/share/postgresql-common/pgdg/apt.postgresql.org.asc\n' > /etc/apt/sources.list.d/pgdg.sources \
 && apt-get update && apt-get install -y --no-install-recommends qpdf poppler-utils git unzip postgresql-client-16 \
 && rm -rf /var/lib/apt/lists/*
COPY --from=composer:2 /usr/bin/composer /usr/bin/composer
WORKDIR /app
