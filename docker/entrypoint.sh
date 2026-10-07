#!/usr/bin/env sh
set -e

cd /var/www

# Compose env_file (or the orchestrator) supplies configuration at runtime.
# A missing key is a deployment error; never create or rotate encryption keys here.
if [ -z "${APP_KEY:-}" ]; then
  echo "APP_KEY must be supplied through runtime configuration." >&2
  exit 1
fi

# Wait for DB if using MySQL
if [ "${DB_CONNECTION:-}" = "mysql" ]; then
  echo "Waiting for MySQL..."
  php -r '$host=getenv("DB_HOST")?:"db"; $port=getenv("DB_PORT")?:"3306"; $db=getenv("DB_DATABASE")?:"menu"; $user=getenv("DB_USERNAME")?:"menu"; $pass=getenv("DB_PASSWORD")?:""; $start=time(); $timeout=60; while(true){ try { new PDO("mysql:host={$host};port={$port};dbname={$db}", $user, $pass); break; } catch(Exception $e){ if(time()-$start>$timeout){ fwrite(STDERR, "DB not ready\n"); exit(1);} sleep(2);} } echo "DB is ready\n";'
fi

if [ "${RUN_MIGRATIONS:-true}" = "true" ]; then
  php artisan migrate --force
fi

if [ "${RUN_STORAGE_LINK:-true}" = "true" ]; then
  php artisan storage:link || true
fi

if [ "${RUN_CONFIG_CACHE:-true}" = "true" ]; then
  php artisan config:cache
  if [ -d "resources/views" ]; then
    php artisan view:cache || true
  fi
fi

# Runtime Artisan commands and newly mounted storage can create root-owned paths.
# Apache renders PDFs as www-data, so retain writable private storage/cache paths.
if [ "$(id -u)" = "0" ]; then
  chown -R www-data:www-data /var/www/storage /var/www/bootstrap/cache
fi

if [ "$#" -gt 0 ]; then
  exec "$@"
fi

exec apache2-foreground
