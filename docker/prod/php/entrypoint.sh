#!/bin/sh
# Prepara o Laravel para produção e inicia o processo pedido (php-fpm, fila ou agendador).
set -e
cd /var/www

mkdir -p storage/app/private/media storage/app/private/uploads storage/framework/cache/data storage/framework/sessions storage/framework/views storage/logs bootstrap/cache
chown -R www-data:www-data storage bootstrap/cache

if [ -z "${APP_KEY}" ]; then
  echo "ERRO: APP_KEY não definida (veja api/.env.production)." >&2
  exit 1
fi

php artisan package:discover --ansi >/dev/null 2>&1 || true

# Migrations só no serviço principal (RUN_MIGRATIONS=1), nunca em paralelo pelos workers.
if [ "${RUN_MIGRATIONS:-0}" = "1" ]; then
  php artisan migrate --force
fi

# Cache de configuração/rotas/eventos/views (leitura única, mais rápido).
php artisan config:cache
php artisan route:cache
php artisan event:cache
php artisan view:cache
chown -R www-data:www-data storage bootstrap/cache

# O servidor de aplicação (php-fpm) gerencia o próprio usuário; filas e agendador rodam sem privilégios.
if [ "$1" = "php-fpm" ]; then
  exec "$@"
fi
exec runuser -u www-data -- "$@"
