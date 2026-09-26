# Operations (Phase 8)

How LexRanked runs in production: caching and instant refresh, security,
monitoring, backups, and the SEO checks that guard releases.

## Instant page refresh (on-demand revalidation)

Pages are statically generated and refreshed every 5 minutes (ISR). On top of
that, WordPress tells the frontend within seconds when public data changes:

```
WordPress save / publish / term edit / recalculation
  → Revalidator (collects changes, sends once at the end of the request)
  → POST https://lexranked.com/api/revalidate/   X-LexRanked-Signature: t=…,v1=HMAC
  → Next.js revalidateTag("lexranked") + revalidatePath(…)
```

Setup (both sides need **the same random secret**, at least 32 characters):

```bash
openssl rand -hex 32
```

| Where | Setting |
|---|---|
| WordPress `wp-config.php` | `define( 'LEXRANKED_REVALIDATE_SECRET', '…' );` (or the env var of the same name) |
| WordPress **LexRanked → Settings** | *Frontend URL* = `https://lexranked.com` |
| Vercel env (server-only) | `REVALIDATE_SECRET=…` |

- Requests are HMAC-SHA256 signed over `timestamp.body`; older than 5 minutes,
  tampered or unsigned → 401, no side effects. Only the `lexranked` tag and
  plain paths are accepted.
- A failed call is retried by WP-cron (1, 2, 3, 4 minutes; five attempts).
  The last result is shown in Settings and in the health check. ISR still
  refreshes pages within 5 minutes if every retry fails.
- The same events bump a *content version* that keys the CMS's cached API
  responses (taxonomy counts). Any change invalidates the cache immediately;
  the TTL is one hour.

## Security

| Layer | Measure |
|---|---|
| Frontend headers | `Content-Security-Policy` (same-origin scripts, no plugins, no framing, forms to self), `Strict-Transport-Security` (2 years, subdomains), `X-Frame-Options: DENY`, `nosniff`, `Referrer-Policy`, `Permissions-Policy`, `Cross-Origin-Opener-Policy` |
| CSP trade-off | ISR pages cannot carry per-request nonces, so Next.js's inline bootstrap scripts need `'unsafe-inline'` in `script-src`. No third-party script sources are allowed |
| Metadata | `htmlLimitedBots: /.*/`: title, canonical, robots and OpenGraph are always in `<head>`, never streamed |
| Secrets | Server-only env vars; `server-only` imports prevent bundling; the e2e test greps the client bundle for the application password |
| WordPress REST | Custom namespace only, `/wp/v2/users` hidden, CPTs not in `/wp/v2`, unknown params rejected, per-route permission callbacks, `nosniff`/`DENY`/`no-referrer`/CORP headers |
| WordPress core | XML-RPC disabled by default (Settings), generator tag removed, application passwords per integration (frontend, worker) with least-privilege roles |
| Rate limits | CMS: per-client limits (HMAC'd IPs), stricter for search. Frontend search: 30/min per client per instance (best effort). **Edge (required):** Cloudflare rate-limiting rules below |
| Webhooks | Signed revalidation (above); research API requires a lease token |
| Audit | Every admin save, research decision, draft apply and settings change is in `lr_audit_log` |

Recommended Cloudflare rules:

- Rate limit `lexranked.com/search/*`: 30 requests / minute / IP, then a managed challenge.
- Rate limit `wp.lexranked.com/wp-json/*`: 300 requests / minute / IP. The frontend and workers authenticate and are exempt in WordPress, but put them behind an IP allow-list if your plan supports it.
- WAF managed rules on; `wp.lexranked.com/wp-login.php` and `/wp-admin/*` restricted by country or IP, or behind Cloudflare Access.
- Block `wp.lexranked.com/xmlrpc.php`.

## Monitoring

| Endpoint | Who | What |
|---|---|---|
| `GET https://lexranked.com/api/health/` | uptime monitor (public) | frontend up, CMS reachable, CMS check levels (names and levels only). **200** ok/degraded, **503** down or critical |
| `GET /wp-json/lexranked/v1/health` | API role / admins | full checks with messages; 503 when critical |
| `wp lexranked health` | cron / on-call | same checks as a table; exit code 1 when critical |
| `GET /wp-json/lexranked/v1/status` | public | version and API contract |

Checks: database schema version · recalculation scheduled and WP-cron not
overdue · last full score calculation (warning > 36 h, critical > 72 h) ·
research jobs stuck (expired lease) or failed with no retries left · editorial
review queue size · instant refresh configured and last call OK · PHP
errors not displayed.

Logs are JSON lines everywhere: Next.js request errors (`instrumentation.ts`,
`source: "nextjs"`), revalidations (`source: "revalidate"`), API client
failures (`source: "lexranked-api"`), the research worker, and backups.
Secrets are never logged. Send Vercel logs to a log drain, and have the
uptime monitor alert on non-200 from `/api/health/`.

Suggested alerting:

1. `/api/health/` returns non-200 for more than 5 minutes → page on-call.
2. `wp lexranked health` exit code 1 (cron every 15 minutes) → email.
3. A spike of Next.js `level:error` log lines → investigate.

## Backups

`scripts/backup.sh` exports the whole database and the uploads, with
checksums and retention. The database dump includes every `lr_*` table:
evidence, snapshots, candidates, research log and audit log.

```bash
# On the WordPress host (system cron, daily 03:17 UTC):
17 3 * * * BACKUP_DIR=/srv/backups/lexranked RETENTION_DAYS=14 /opt/lexranked/scripts/backup.sh >> /var/log/lexranked-backup.log 2>&1
# Locally against docker-compose:
scripts/backup.sh --docker
```

- The script refuses to keep an empty or truncated dump (exit 1) and writes
  files with mode 600. Backups contain private data.
- **Copy BACKUP_DIR off the host** (for example `rclone sync` to encrypted
  object storage with versioning). A backup that stays on the same server is
  not a backup.
- The managed host's own daily snapshots are a second layer, not a replacement.

**Restore** (test it quarterly on a staging copy):

```bash
cd lexranked-<stamp> && sha256sum -c SHA256SUMS
gunzip -c database.sql.gz | wp db import -
tar -C wp-content -xzf uploads.tar.gz
wp lexranked status && wp lexranked verify-snapshots && wp lexranked health
```

`verify-snapshots` proves that the restored rankings reproduce exactly from
their stored inputs.

## SEO and structured-data validation

`frontend/lib/seo/audit.ts` checks rendered HTML:

- title, description, robots and canonical (absolute https URL, self-referencing);
- exactly one `<h1>`;
- OpenGraph and Twitter tags;
- valid JSON-LD with `https://schema.org` context and per-type rules (BreadcrumbList positions, ItemList, FAQPage questions and answers, Article headline, date and author, names on Organization, Person and LegalService);
- **no review or AggregateRating markup**;
- sitemap consistency: no noindex page in the sitemap, and a warning for indexable pages missing from it.

```bash
AUDIT_BASE_URL=http://127.0.0.1:3000 AUDIT_SITE_URL=https://lexranked.com npm run audit:seo
```

It crawls the sitemap plus internal links (bounded) and fails on any
error-level finding. CI runs it on every pull request against the
end-to-end build (`scripts/wp-integration-test.sh --frontend`). Run it
against staging before each release.

## Performance

- Pages: ISR, served from the CDN, with instant refresh on change and self-hosted fonts.
- Images: the CMS provides width and height, so there is no layout shift.
- Client JavaScript: only the ranking finder and the mobile menu.
- CMS: taxonomy counts are cached by content version, and rankings are served from stored snapshots, so reads never compute scores.
- Dependencies are kept current by Dependabot (`.github/dependabot.yml`: weekly, grouped Next.js and React updates).
