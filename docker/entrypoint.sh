#!/bin/sh
set -eu

mkdir -p storage/framework/cache storage/framework/sessions storage/framework/views storage/logs
chown -R www-data:www-data storage bootstrap/cache

if [ -z "${APP_KEY:-}" ]; then
    KEY_FILE="storage/app/.app_key"
    mkdir -p storage/app
    KEY_LOCK="storage/app/.app_key.lock"
    if mkdir "$KEY_LOCK" 2>/dev/null; then
        if [ ! -f "$KEY_FILE" ]; then
            printf 'base64:%s' "$(openssl rand -base64 32)" > "${KEY_FILE}.tmp"
            mv "${KEY_FILE}.tmp" "$KEY_FILE"
        fi
        rmdir "$KEY_LOCK"
    else
        attempt=0
        while [ ! -f "$KEY_FILE" ]; do
            attempt=$((attempt + 1))
            if [ "$attempt" -ge 30 ]; then echo "APP_KEY tidak berhasil dibuat." >&2; exit 1; fi
            sleep 1
        done
    fi
    APP_KEY="$(cat "$KEY_FILE")"
    export APP_KEY
fi

if [ "${DB_CONNECTION:-}" = "pgsql" ]; then
    echo "Menunggu PostgreSQL..."
    attempt=0
    until php -r 'try { new PDO("pgsql:host=".getenv("DB_HOST").";port=".(getenv("DB_PORT") ?: 5432).";dbname=".getenv("DB_DATABASE"), getenv("DB_USERNAME"), getenv("DB_PASSWORD")); } catch (Throwable $e) { exit(1); }'; do
        attempt=$((attempt + 1))
        if [ "$attempt" -ge 30 ]; then echo "PostgreSQL tidak dapat dihubungi." >&2; exit 1; fi
        sleep 2
    done
fi

if [ "${RUN_MIGRATIONS:-true}" = "true" ]; then
    php artisan migrate --force --isolated
fi

SEED_MARKER="storage/app/.database_seeded"
if [ "${SEED_DATABASE:-false}" = "true" ] && [ ! -f "$SEED_MARKER" ]; then
    SEED_LOCK="storage/app/.database_seed.lock"
    if mkdir "$SEED_LOCK" 2>/dev/null; then
        if [ ! -f "$SEED_MARKER" ]; then
            echo "Mengisi katalog dan akun awal..."
            php artisan db:seed --force
            touch "$SEED_MARKER"
        fi
        rmdir "$SEED_LOCK"
    else
        attempt=0
        while [ ! -f "$SEED_MARKER" ]; do
            attempt=$((attempt + 1))
            if [ "$attempt" -ge 120 ]; then echo "Seeder instance utama tidak selesai." >&2; exit 1; fi
            sleep 1
        done
    fi
fi
if [ "${OPTIMIZE_APP:-true}" = "true" ]; then
    OPTIMIZE_LOCK="storage/app/.optimize.lock"
    if mkdir "$OPTIMIZE_LOCK" 2>/dev/null; then
        # Storage dipakai bersama oleh seluruh replica. Hindari beberapa proses
        # menulis compiled view/cache Laravel pada waktu yang sama saat deploy.
        trap 'rmdir "$OPTIMIZE_LOCK" 2>/dev/null || true' EXIT INT TERM
        php artisan view:clear
        php artisan optimize
        rmdir "$OPTIMIZE_LOCK"
        trap - EXIT INT TERM
    else
        attempt=0
        while [ -d "$OPTIMIZE_LOCK" ]; do
            attempt=$((attempt + 1))
            if [ "$attempt" -ge 120 ]; then echo "Optimasi instance utama tidak selesai." >&2; exit 1; fi
            sleep 1
        done
    fi
fi

exec "$@"
