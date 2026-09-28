#!/bin/bash
# Extensions test: a typical small-business PHP app (Laravel/WordPress-style)
# usually needs gd (images), intl (i18n/number formatting), zip (archives) and
# pdo_pgsql (this app's database), none of which ship in the stock runner
# image. Timed with `date` markers so the report can cite real install time,
# not a guess.
set -e

echo "SETUP_START $(date -u +%s)"

apt-get update -y

# gd needs its own image libs; libzip-dev is needed for the zip extension.
apt-get install -y --no-install-recommends \
    libpq-dev \
    libpng-dev \
    libjpeg-dev \
    libfreetype6-dev \
    libzip-dev \
    libicu-dev

docker-php-ext-configure gd --with-freetype --with-jpeg
docker-php-ext-install -j"$(nproc)" gd intl zip pdo_pgsql

echo "SETUP_EXT_DONE $(date -u +%s)"

date -u +"%Y-%m-%dT%H:%M:%SZ" > /tmp/osc-setup-ran

php "$(dirname "$0")/db/migrate.php"

echo "SETUP_END $(date -u +%s)"
