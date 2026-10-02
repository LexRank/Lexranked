# Connecting a WordPress installation

This guide connects an existing WordPress site (e.g. `wp.lexranked.com`) to
the LexRanked frontend.

## 1. Requirements

- WordPress ≥ 6.5, PHP ≥ 8.2, HTTPS.
- Pretty permalinks enabled (**Settings › Permalinks › Post name**), otherwise
  `/wp-json/` URLs do not work.

## 2. Install the plugin

1. Get the ZIP: download the `lexranked-core-plugin` artifact from the latest
   green CI run, or build it locally with `scripts/build-plugin-zip.sh`
   (→ `dist/lexranked-core-<version>.zip`).
2. **Plugins › Add New › Upload Plugin** → choose the ZIP → **Install** →
   **Activate**.
3. Check: open `https://<your-wp>/wp-json/lexranked/v1/status` — it must
   return `{"status":"ok", …}`.

Updating: upload a newer ZIP (WordPress offers "Replace current with
uploaded"). Database migrations run automatically.

## 3. Configure

**LexRanked › Settings**:

- **Frontend URL**: `https://lexranked.com` (or your Vercel preview URL).
- Leave **Headless redirect** off until the frontend is live.
- Enable **Trust CF-Connecting-IP** only if WordPress is reachable
  exclusively through Cloudflare.

## 4. (Optional) Load demo data to test the connection

Demo data is clearly marked mock data (Miami · Personal Injury, 8 lawyers,
3 firms, 1 ranking). With WP-CLI on the server:

```bash
wp lexranked seed-demo     # create
wp lexranked status        # counts
wp lexranked purge-demo --yes   # remove before entering real data
```

Without WP-CLI you can enter data by hand: **LexRanked › Locations** (add
`Florida` with state code `FL`, then `Miami` with parent Florida),
**Practice Areas**, then **Law Firms** and **Lawyers**.

## 5. Create the API user for the frontend

1. **Users › Add New**: username e.g. `lexranked-frontend`, role
   **LexRanked API** (it cannot edit anything).
2. Edit that user → **Application Passwords** → name `nextjs` → **Add** →
   copy the generated password (shown once).

## 6. Configure the frontend

Server-side environment variables (Vercel project settings or
`frontend/.env.local` locally):

```
NEXT_PUBLIC_SITE_URL=https://lexranked.com
WORDPRESS_API_URL=https://<your-wp>/wp-json
WORDPRESS_USERNAME=lexranked-frontend
WORDPRESS_APP_PASSWORD=<application password>
```

## 7. Verify

Open `https://<frontend>/status/`. Every check should show **✓ OK** and list
the counts from WordPress. The page is `noindex` and excluded from
`robots.txt`; it shows only the API host and whether credentials are set,
never their values.

## Troubleshooting

| Symptom | Cause / fix |
|---------|-------------|
| `/wp-json/…` returns HTML 404 | Enable pretty permalinks. |
| `/status/` shows `HTTP 401 · incorrect_password` | Wrong Application Password, or a host/proxy strips the `Authorization` header (on Apache ensure `.htaccess` passes `HTTP_AUTHORIZATION`). |
| Application Passwords menu missing | WordPress requires HTTPS for Application Passwords. |
| `HTTP 429 · lexranked_rate_limited` | Frontend not authenticated; set the API user credentials. |
| `network_error` / `timeout` | WordPress unreachable from Vercel (firewall, Cloudflare rule, wrong URL). |
