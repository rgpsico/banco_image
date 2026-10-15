#!/bin/sh
# Prepara o Laravel na primeira execução (clone limpo): dependências, .env, chave e migrations.
set -e
cd /var/www

[ -d vendor ] || composer install --no-interaction --prefer-dist

if [ ! -f .env ]; then
  cp .env.example .env
  php artisan key:generate --force
fi

if [ "${RUN_MIGRATIONS:-0}" = "1" ]; then
  php artisan migrate --force
  php artisan db:seed --force   # licenças padrão + dados de demonstração (somente APP_ENV=local)
fi

exec "$@"
