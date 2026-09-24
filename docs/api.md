# REST API

Namespace: `/wp-json/lexranked/v1/`

## Implemented (Phase 1)

### `GET /status`

Public health/version endpoint. No private data. `Cache-Control: no-store`.

```json
{
  "status": "ok",
  "service": "lexranked-core",
  "pluginVersion": "0.1.0",
  "apiVersion": "1.0.0",
  "namespace": "lexranked/v1"
}
```

## Planned (Phase 2)

| Method | Path | Notes |
|--------|------|-------|
| GET | `/lawyers` | paginated, filterable, sortable |
| GET | `/lawyers/{id}` | `id` numeric or slug |
| GET | `/law-firms`, `/law-firms/{id}` | |
| GET | `/rankings`, `/rankings/{id}` | |
| GET | `/states`, `/cities`, `/practice-areas` | |
| GET | `/sources` | public source metadata only |
| GET | `/verifications` | public verification summaries only |
| GET | `/search` | not indexable |

### Conventions

- **Pagination:** `page` (≥1), `per_page` (1–100, default 20). Response
  headers `X-WP-Total`, `X-WP-TotalPages`.
- **Filtering:** whitelisted params only (e.g. `state`, `city`,
  `practice_area`, `verification_status`). Unknown params are rejected (400).
- **Sorting:** `orderby` from a whitelist (`score`, `name`, `updated_at`),
  `order` = `asc|desc`.
- **Validation:** every arg has `type`, `sanitize_callback`, `validate_callback`.
- **Permissions:** every route has an explicit `permission_callback`. Public
  GETs return only public DTO fields; private fields (emails marked private,
  internal research data, reviewer notes) require capabilities.
- **Errors:** `WP_Error` with a stable `code`, e.g. `lexranked_not_found`.
- **Versioning:** breaking DTO changes require `lexranked/v2`.

### DTO: Lawyer (public)

```json
{
  "id": 123,
  "slug": "john-smith",
  "name": "John Smith",
  "firm": { "id": 55, "name": "Smith Law Group", "slug": "smith-law-group" },
  "location": { "city": "Miami", "state": "Florida", "stateCode": "FL" },
  "practiceAreas": [{ "slug": "personal-injury", "name": "Personal Injury" }],
  "rating": 4.9,
  "reviewCount": 387,
  "ranking": { "score": 94.21, "scoreVersion": "v1.0", "position": 1 },
  "commercial": { "status": "free" },
  "verification": { "status": "verified", "verifiedAt": "2026-09-23" },
  "lastVerifiedAt": "2026-09-23"
}
```

Note `ranking` and `commercial` are sibling objects: commercial status never
affects `ranking`. (The example values above illustrate shape only.)

## Authentication

Server-to-server calls (Next.js server, workers) use WordPress Application
Passwords over HTTPS with a dedicated least-privilege user. Credentials are
server-side environment variables only.
