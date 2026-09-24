# Deployment

## Environments

| Environment | Frontend | Backend |
|-------------|----------|---------|
| Local | `npm run dev` (http://localhost:3000) | `docker compose up` (http://localhost:8080) |
| Preview | Vercel preview per PR (always `noindex`) | staging WordPress (recommended) |
| Production | Vercel, `main` branch → `lexranked.com` | `wp.lexranked.com` |

## Workflow

feature branch → commit → pull request → CI → Vercel preview → review →
merge to `main` → production deploy. No manual edits to production files.

## CI (`.github/workflows/ci.yml`)

On every PR and push to `main`:

- **Frontend:** `npm ci`, lint, typecheck, test, build (Node from `.nvmrc`).
- **Plugin:** `composer install`, `php -l` + PHPCS (WPCS, PHPCompatibilityWP),
  PHPUnit on PHP 8.2 / 8.3 / 8.4.
- **Hygiene:** fails if any `.env` file is committed.

Run all of it locally with `scripts/check.sh`.

## Vercel setup

1. Import the GitHub repo; set **Root Directory** to `frontend`.
2. Production branch: `main`.
3. Environment variables (Production / Preview separately):
   `NEXT_PUBLIC_SITE_URL`, `WORDPRESS_API_URL`, `WORDPRESS_USERNAME`,
   `WORDPRESS_APP_PASSWORD`, and `ALLOW_INDEXING=true` **only** in Production
   once launch-ready.
4. Mark the WordPress password as *Sensitive*.

## WordPress (wp.lexranked.com)

- Managed WordPress host with PHP ≥ 8.2, HTTPS, daily backups.
- Deploy the plugin from `wordpress/plugins/lexranked-core` (without
  `vendor/`, `tests/`, dev config) — automation added in Phase 8.
- No public theme: install a minimal theme and redirect front-end requests
  to `lexranked.com`; send `X-Robots-Tag: noindex` on the WP host.
- Create a dedicated API user with an Application Password for the
  frontend/workers.

## Cloudflare

- `lexranked.com` → Vercel (DNS only / proxied per Vercel guidance).
- `wp.lexranked.com` → WordPress host, proxied; WAF rules to protect
  `/wp-admin` and `/wp-login.php`; rate limits on `/wp-json/`.

## Secrets

Never committed. Stored in Vercel / host environment settings. `.env.example`
lists every variable with empty values.
