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
- **WordPress integration:** builds the plugin ZIP, runs it in real
  WordPress + MariaDB (Docker), seeds demo data and asserts on every API
  endpoint, auth and rate limiting (`scripts/wp-integration-test.sh`). The
  installable ZIP is uploaded as the `lexranked-core-plugin` artifact.
- **Hygiene:** fails if any `.env` file is committed.

Run all of it locally with `scripts/check.sh`.

## Vercel setup

1. Import the GitHub repo; set **Root Directory** to `frontend`.
2. Production branch: `main`.
3. Environment variables (Production / Preview separately):
   `NEXT_PUBLIC_SITE_URL`, `WORDPRESS_API_URL`, `WORDPRESS_USERNAME`,
   `WORDPRESS_APP_PASSWORD`, `REVALIDATE_SECRET` (instant refresh; same value
   as `LEXRANKED_REVALIDATE_SECRET` in wp-config.php), and `ALLOW_INDEXING=true`
   **only** in Production once launch-ready.
4. Mark the WordPress password and `REVALIDATE_SECRET` as *Sensitive*.

## WordPress (wp.lexranked.com)

- Managed WordPress host with PHP ≥ 8.2, HTTPS, daily backups.
- Install/update the plugin from the CI artifact ZIP (or
  `scripts/build-plugin-zip.sh`); it contains no `vendor/`, tests or dev
  config. Step-by-step: [connecting-wordpress.md](connecting-wordpress.md).
  Automated deployment is planned for Phase 8.
- No public theme: install a minimal theme and redirect front-end requests
  to `lexranked.com`; send `X-Robots-Tag: noindex` on the WP host.
- Create a dedicated user with the **LexRanked API** role and an
  Application Password for the frontend.
- WP-cron must run (real cron hitting `wp-cron.php`, or `wp cron event run
  --due-now` every few minutes): it recalculates rankings and runs internal
  research jobs (verification expiry, ranking recalculation).

## Instant refresh, monitoring and backups

See [operations.md](operations.md): shared `REVALIDATE_SECRET` /
`LEXRANKED_REVALIDATE_SECRET`, uptime monitoring of `/api/health/`,
`wp lexranked health` in cron, daily `scripts/backup.sh` with off-site copy,
and the Cloudflare rules.

## Research worker

- Any Node ≥ 22 host with outbound HTTPS (a small VM, container or
  scheduled job); it needs no inbound ports and no database access.
- Create a separate user with the **LexRanked Research Worker** role and its
  own Application Password; set `LEXRANKED_API_URL`,
  `LEXRANKED_WORKER_USER`, `LEXRANKED_WORKER_APP_PASSWORD`,
  `LEXRANKED_DATA_DIR` (see `docs/research.md`).
- Run `node dist/cli.js --loop` under a supervisor (systemd, container
  restart policy) or `--once` from cron. Crashes are safe: the job resumes
  after its lease expires.
- Run it on a network segment without access to internal services (the
  SSRF guard blocks private addresses, but defense in depth matters).
- AI (optional): set `OPENAI_API_KEY` on the worker (the model is picked from `src/ai/models.ts`; `OPENAI_MODEL` overrides it)
  only, set a per-job cap (`OPENAI_MAX_CALLS_PER_JOB`) and enable *AI
  assistance* in WordPress Settings. See `docs/ai.md`.

## Cloudflare

- `lexranked.com` → Vercel (DNS only / proxied per Vercel guidance).
- `wp.lexranked.com` → WordPress host, proxied; WAF rules to protect
  `/wp-admin` and `/wp-login.php`; rate limits on `/wp-json/`.

## Secrets

Never committed. Stored in Vercel / host environment settings. `.env.example`
lists every variable with empty values.
