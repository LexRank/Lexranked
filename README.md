# LexRanked

Data-driven, source-backed rankings of lawyers and law firms in the United
States — [lexranked.com](https://lexranked.com).

LexRanked is a **headless** platform: WordPress + the `lexranked-core` plugin
is the CMS/API; a Next.js app is the only public frontend. See
[`docs/architecture.md`](docs/architecture.md).

## Principles

accuracy > quantity · verified data > generated assumptions · useful pages >
many pages · transparent methodology > black-box rankings · reproducible
rankings > subjective rankings. **Payment never changes an organic ranking.**

## Repository

| Path | What |
|------|------|
| `frontend/` | Next.js 16 (App Router, TypeScript) public site |
| `wordpress/plugins/lexranked-core/` | All LexRanked business logic + REST API (`/wp-json/lexranked/v1/`) |
| `workers/research/` | TypeScript research worker (Phase 5); `workers/*` others in later phases |
| `database/` | Reference schema and migrations |
| `docs/` | Architecture, data model, methodology, API, research, content, deployment |
| `scripts/check.sh` | Runs every CI check locally |

## Requirements

Node.js 22 (`.nvmrc`), PHP ≥ 8.2 + Composer 2, Docker (for local WordPress).

## Getting started

```bash
cp .env.example .env                       # local docker credentials
cp .env.example frontend/.env.local        # frontend config (server-only vars stay server-side)

# WordPress + MariaDB at http://localhost:8080 (plugin is bind-mounted)
docker compose up -d
docker compose run --rm wpcli wp core install --url=http://localhost:8080 --title=LexRanked \
  --admin_user=admin --admin_password=admin --admin_email=admin@example.com --skip-email
docker compose run --rm wpcli wp rewrite structure '/%postname%/'
docker compose run --rm wpcli wp plugin activate lexranked-core
docker compose run --rm wpcli wp lexranked seed-demo      # clearly-labelled mock data
curl http://localhost:8080/wp-json/lexranked/v1/lawyers

# Frontend at http://localhost:3000 (set WORDPRESS_API_URL=http://localhost:8080/wp-json
# in frontend/.env.local), then open http://localhost:3000/status/
cd frontend && npm install && npm run dev

# Plugin tooling
cd wordpress/plugins/lexranked-core && composer install && composer lint && composer test

# Research worker (see docs/research.md): create a worker user + a job, then run it
docker compose run --rm wpcli wp user create research-worker research@example.com --role=lexranked_worker
docker compose run --rm wpcli wp user application-password create research-worker local --porcelain
docker compose run --rm wpcli wp lexranked research-job candidate_discovery --params='{"dataset":"fictional-demo"}'
cd workers/research && npm ci && npm run build && \
  LEXRANKED_API_URL=http://localhost:8080/wp-json/lexranked/v1 LEXRANKED_WORKER_USER=research-worker \
  LEXRANKED_WORKER_APP_PASSWORD='…' LEXRANKED_DATA_DIR=fixtures/datasets node dist/cli.js --once
```

## Checks

```bash
scripts/check.sh                 # lint, typecheck, tests, build — same as CI
scripts/wp-integration-test.sh   # real WordPress in Docker + API + research worker (crash/resume) assertions
scripts/wp-integration-test.sh --frontend   # …plus Next.js built against it (end-to-end)
scripts/build-plugin-zip.sh      # installable plugin ZIP → dist/
```

Connecting your own WordPress: [`docs/connecting-wordpress.md`](docs/connecting-wordpress.md).
Adding cities, rankings, lawyers and articles: [`docs/editor-guide.md`](docs/editor-guide.md). AI: [`docs/ai.md`](docs/ai.md). Operations (monitoring, backups, security, revalidation): [`docs/operations.md`](docs/operations.md).

## Roadmap

1. ✅ Repository and architecture
2. ✅ WordPress core: entities, REST API, admin UI, validation (+ frontend API client and `/status/`)
3. ✅ Frontend: public pages, design system, SEO/GEO content, structured data, sitemap
4. ✅ **Ranking engine**: ScoreCalculator, RankingEngine, ScoreVersion, snapshots, history, breakdowns
5. ✅ **Research engine**: leased/resumable jobs with retries, candidates + deterministic matching, source-backed claims, rule-based verification, editorial review, TypeScript worker
6. ✅ **AI assistance**: quote-checked extraction & classification, advisory match review, ranking content drafts with deterministic + AI QA — strict schemas, never published automatically
7. ✅ **Content engine**: guides at `/articles/`, editorial text on hub pages and profiles, AI drafts (ranking, hub, profile, article) with QA — drafts only
8. ✅ **Production hardening**: signed instant revalidation, response cache, CSP/HSTS, health monitoring, backups, automated SEO/structured-data audit
9. Commercial features (kept separate from organic ranking)

## Security

Never commit secrets. Server-only variables (no `NEXT_PUBLIC_` prefix) are
read only in modules guarded by `server-only`. Report issues privately to the
maintainers.
