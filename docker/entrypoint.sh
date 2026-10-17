#!/bin/sh
# Migra el esquema (CREATE EXTENSION va en infra/postgres/init) y arranca el servidor.
set -e
php artisan migrate --force
exec frankenphp run --config /etc/caddy/Caddyfile