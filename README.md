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
| `workers/` | Background research / scoring / content / QA (later phases) |
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
# then finish the WP install in the browser and activate "LexRanked Core"
curl http://localhost:8080/wp-json/lexranked/v1/status

# Frontend at http://localhost:3000
cd frontend && npm install && npm run dev

# Plugin tooling
cd wordpress/plugins/lexranked-core && composer install && composer lint && composer test
```

## Checks

```bash
scripts/check.sh   # lint, typecheck, tests, build — same as CI
```

## Roadmap

1. ✅ **Repository and architecture** (this phase)
2. WordPress core: entities, REST API, admin UI, validation
3. Frontend: pages, SEO, structured data, sitemap
4. Ranking engine: ScoreCalculator, RankingEngine, ScoreVersion, RankingSnapshot
5. Research engine: resumable jobs, evidence, verification
6. OpenAI integration: structured extraction, strict schemas
7. Content engine (drafts only)
8. Production hardening
9. Commercial features (kept separate from organic ranking)

## Security

Never commit secrets. Server-only variables (no `NEXT_PUBLIC_` prefix) are
read only in modules guarded by `server-only`. Report issues privately to the
maintainers.
