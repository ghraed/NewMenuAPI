# Deployment Checklist (Production)

## 1) Docker and Apache

- Install Docker + Compose (official repo)
- Install Apache + enable modules: `proxy`, `proxy_http`, `headers`, `rewrite`

## 2) Backend environment

- Create `.env.production` with:
  - `APP_URL=https://your-domain`
  - DB settings (`DB_*`)
  - `SESSION_DOMAIN=your-domain`
- Update `docker/db.env` to match DB values

## 3) Start backend

```bash
cd Menu_API

docker compose -f docker-compose.prod.yml up -d --build
```

## 4) Seed admin user (safe)

Set in `.env.production` (example):

```
ADMIN_EMAIL=admin@your-domain
ADMIN_PASSWORD=StrongPass123!
ADMIN_NAME=Admin
ADMIN_RESTAURANT_NAME=My Restaurant
ADMIN_RESTAURANT_SLUG=my-restaurant
ADMIN_RESTAURANT_ADDRESS=123 Main St
ADMIN_RESTAURANT_DESCRIPTION=Default admin restaurant
```

Then run:

```bash
docker exec -it menu_api_app php artisan seed:prod
```

## 5) Apache reverse proxy (HTTP)

- Copy `deploy/apache-proxy.conf` to `/etc/apache2/sites-available/your-domain.conf`
- Update domain + DocumentRoot
- Enable site and reload Apache

## 6) Frontend build + deploy

```bash
cd Menu_React
printf 'VITE_API_URL=https://your-domain/api\n' > .env.production
npm ci
npm run build

sudo rm -rf /var/www/menu_frontend/*
sudo cp -R dist/. /var/www/menu_frontend/
```

## 7) HTTPS

```bash
sudo apt install -y certbot python3-certbot-apache
sudo certbot --apache -d your-domain -d www.your-domain --redirect
```

## 8) Verify

- `https://your-domain`
- `https://your-domain/api/test`

## 9) Tenant Domains (Multi-tenant Host Routing)

- Keep wildcard DNS for subdomains:
  - `*.rozer.fun -> your server IP`
- For custom domains:
  - Point customer DNS (`A` or `CNAME`) to your server.
  - Issue and attach SSL certificate manually for that custom domain.
- Keep reverse proxy host forwarding enabled:
  - Apache: `ProxyPreserveHost On`
- Add domain mapping in DB (`restaurant_domains`):
  - `restaurant_id`: target restaurant
  - `domain`: exact host (lowercase)
  - `kind`: `subdomain` or `custom`
  - `is_primary`: `true` for canonical tenant host
  - `verified_at`: timestamp when domain/cert is ready
- Smoke test:
  - `https://alpha.rozer.fun/api/menu/dishes`
  - `https://sigma.rozer.fun/api/menu/dishes`
  - unknown host should return tenant-not-found behavior.
