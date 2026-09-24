# Workers

Background services that run outside the request path. **Not implemented in Phase 1.**

| Directory   | Phase | Responsibility |
|-------------|-------|----------------|
| `research/` | 5     | Resumable research jobs: candidate discovery, source collection, normalization, entity matching, fact extraction. |
| `scoring/`  | 4     | Batch ranking recalculation and snapshot creation (the scoring rules themselves live in the plugin's `Ranking/` module so there is one source of truth). |
| `content/`  | 6–7   | Content drafting from verified, stored data. Output is always a WordPress **draft**. |
| `qa/`       | 6–7   | Fact, SEO and quality validation of drafts (`needs_review` / `ready_for_review`). |

Rules that apply to every worker (see `docs/research.md` and `docs/content.md`):

- Workers talk to WordPress only through the authenticated LexRanked REST API.
- Secrets come from environment variables; nothing is logged that could contain a secret.
- Every external call has a timeout, bounded retries with exponential backoff, and a failure state.
- AI output is validated against strict JSON schemas and is never a source of truth.
