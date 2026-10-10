# LexRanked frontend

Next.js 16 App Router + TypeScript. The only public frontend for
lexranked.com; all data comes from the LexRanked REST API.

```bash
npm install
npm run dev        # http://localhost:3000
npm run lint       # ESLint (eslint-config-next), zero warnings allowed
npm run typecheck  # next typegen + tsc
npm test           # Vitest unit tests (tests/)
npm run build
```

## Routes

`/`, `/rankings/`, `/rankings/{state}/{city?}/{practice?}/`, `/lawyers/`,
`/lawyers/{slug}/`, `/law-firms/`, `/law-firms/{slug}/`, `/states/`,
`/states/{state}/`, `/cities/`, `/cities/{city}/`, `/practice-areas/`,
`/practice-areas/{slug}/`, `/methodology/`, `/verified/`, `/search/`
(noindex), `/status/` (noindex), `/sitemap.xml`, `/robots.txt`.

The design system is described in `docs/design.md`. The rules for when pages
exist and when they are indexed are in `docs/content.md`.

## Layout

- `app/` - routes (`layout.tsx`, `page.tsx`, `robots.ts`, `status/` connection diagnostics, noindex)
- `components/` - shared UI
- `lib/config/site.ts` - public config (safe for the browser)
- `lib/config/server-env.ts` - server-only config (guarded by `server-only`)
- `lib/seo/` - `buildMetadata` (canonical, robots, OpenGraph, Twitter), JSON-LD builders, URLs
- `lib/content/` - page eligibility, ranking URL resolution, sitemap assembly (pure, tested)
- `lib/data/loaders.ts` - graceful API loading + pagination helpers
- `lib/format.ts`, `lib/methodology.ts` - presentation helpers and public methodology text
- `components/` - design-system components (see docs/design.md)
- `lib/slug.ts` - slug generation/validation
- `lib/wordpress/` - server-only LexRanked API client (timeouts, retries with
  backoff, typed errors, optional Application Password auth) and typed endpoint functions
- `types/api.ts` - API DTO types (mirror `docs/api.md`)
- `tests/` - unit tests

Environment variables: see the root `.env.example`. Put local values in
`frontend/.env.local`.
