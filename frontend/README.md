# LexRanked frontend

Next.js 16 App Router + TypeScript. The only public frontend for
lexranked.com; data comes from the LexRanked REST API (Phase 3).

```bash
npm install
npm run dev        # http://localhost:3000
npm run lint       # ESLint (eslint-config-next), zero warnings allowed
npm run typecheck  # next typegen + tsc
npm test           # Vitest unit tests (tests/)
npm run build
```

## Layout

- `app/` — routes (`layout.tsx`, `page.tsx`, `robots.ts`, `status/` connection diagnostics, noindex)
- `components/` — shared UI
- `lib/config/site.ts` — public config (safe for the browser)
- `lib/config/server-env.ts` — server-only config (guarded by `server-only`)
- `lib/seo/` — canonical URL helpers (metadata/JSON-LD utilities in Phase 3)
- `lib/slug.ts` — slug generation/validation
- `lib/wordpress/` — server-only LexRanked API client (timeouts, retries with
  backoff, typed errors, optional Application Password auth) and typed endpoint functions
- `types/api.ts` — API DTO types (mirror `docs/api.md`)
- `tests/` — unit tests

Environment variables: see the root `.env.example`. Put local values in
`frontend/.env.local`.
