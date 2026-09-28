#!/bin/bash
# Runs once at container start, as root, after composer install and before Apache starts.
# This is the only hook the php-runner gives an app to install system packages or run
# migrations, so it carries both jobs here.
set -e

echo "osc-php-portal setup.sh starting"

# The stock php:8.3-apache image does not ship pdo_pgsql. libpq-dev is needed to build it.
apt-get update -y
apt-get install -y --no-install-recommends libpq-dev
docker-php-ext-install pdo_pgsql

# Marker file used by /status to prove setup.sh ran, and to detect a restart:
# this file lives on local disk only, so it disappears whenever the container
# is recreated, which is exactly what a My App restart does.
date -u +"%Y-%m-%dT%H:%M:%SZ" > /tmp/osc-setup-ran

# Idempotent schema and seed data.
php "$(dirname "$0")/db/migrate.php"

echo "osc-php-portal setup.sh done"
