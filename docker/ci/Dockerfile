# Test runner with a coverage driver (pcov), used locally and mirroring the CI php-tests job.
FROM php:8.4-cli-bookworm
COPY --from=mlocati/php-extension-installer:2 /usr/bin/install-php-extensions /usr/local/bin/
RUN install-php-extensions pdo_pgsql intl gd zip bcmath exif pcntl pcov \
 && apt-get update && apt-get install -y --no-install-recommends qpdf poppler-utils git unzip \
 && rm -rf /var/lib/apt/lists/*
COPY --from=composer:2 /usr/bin/composer /usr/bin/composer
WORKDIR /app
