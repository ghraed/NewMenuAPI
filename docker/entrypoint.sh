#!/usr/bin/env sh
set -e

cd /var/www

if [ ! -f .env ]; then
  if [ -f .env.production ]; then
    echo "No .env found. Copying .env.production -> .env"
    cp .env.production .env
  else
    echo "No .env found. Copying .env.example -> .env"
    cp .env.example .env
  fi
fi

# Ensure APP_KEY exists
APP_KEY_VALUE="${APP_KEY:-}"
if [ -n "$APP_KEY_VALUE" ]; then
  if grep -q '^APP_KEY=' .env; then
    sed -i "s|^APP_KEY=.*|APP_KEY=${APP_KEY_VALUE}|" .env
  else
    echo "APP_KEY=${APP_KEY_VALUE}" >> .env
  fi
else
  CURRENT_KEY=$(grep -E '^APP_KEY=' .env | cut -d= -f2 || true)
  if [ -z "$CURRENT_KEY" ]; then
    php artisan key:generate --force
  fi
fi

# Wait for DB if using MySQL
if [ "${DB_CONNECTION}" = "mysql" ]; then
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
  php artisan view:cache || true
fi

exec apache2-foreground
