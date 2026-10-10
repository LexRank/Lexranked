# Workers

Background services that run outside the request path.

| Directory   | Phase | Status | Responsibility |
|-------------|-------|--------|----------------|
| `research/` | 5     | ✅ implemented | Claims research jobs from WordPress (leases, heartbeats, resume), turns curated seed datasets and website structured data into candidates, claims and verification requests. See [`docs/research.md`](../docs/research.md). |
| `scoring/`  | 4     | in plugin | Ranking recalculation runs inside WordPress (`ranking_recalculation` jobs / WP-cron); the scoring rules live in the plugin's `Ranking/` module so there is one source of truth. |
| `content/`, `qa/` | 6-7 | ranking drafts + QA in `research/` (Phase 6) | Content drafting from stored facts with deterministic and AI QA ([`docs/ai.md`](../docs/ai.md)); output is always a WordPress **draft**. Profile/hub/article drafts follow in Phase 7. |

Rules that apply to every worker (see `docs/research.md` and `docs/content.md`):

- Workers talk to WordPress only through the authenticated LexRanked REST API
  and hold only the least-privilege role they need.
- WordPress validates and applies everything; workers never publish.
- Secrets come from environment variables; nothing is logged that could contain a secret.
- Every external call has a timeout, bounded retries with exponential backoff, and a failure state.
- AI output is validated against strict JSON schemas and is never a source of truth.
