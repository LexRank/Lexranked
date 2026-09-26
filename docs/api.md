# REST API

Namespace: `/wp-json/lexranked/v1/` · API contract version: `1.2.0`
(`X-LexRanked-API` response header).

All endpoints are `GET`. Responses are stable DTOs built by pure mappers in
`src/REST/DTO/` — raw WordPress objects are never returned, and the custom
post types are **not** exposed through `/wp/v2` (ADR-010).

## Conventions

| Topic | Rule |
|-------|------|
| Pagination | `page` (≥1), `per_page` (1–100, default 20). Headers `X-WP-Total`, `X-WP-TotalPages`. |
| Sorting | `orderby` from a per-endpoint whitelist, `order=asc\|desc`. Ties always break on `id ASC` (deterministic). |
| Validation | Every arg has a type/enum/pattern. **Unknown query parameters → 400 `lexranked_invalid_param`.** Out-of-range values → 400 `rest_invalid_param`. |
| Visibility | Only `publish`ed records. Private fields (lawyer email, internal notes, reviewer identity, research data) are never in `context=view`. |
| `context=edit` | Adds a `private` block on lawyer detail; requires `edit_posts` (401 anonymous, 403 for the API role). |
| Errors | `{ "code": "lexranked_not_found", "message": "…", "data": { "status": 404 } }` |
| Caching | Public: `Cache-Control: public, max-age=60, s-maxage=300`. Authenticated/private: `no-store`. |
| Rate limits | Anonymous: `rate_limit_per_minute` (default 120) per client; `/search`: `search_rate_per_minute` (default 30). 429 with `Retry-After`. Users with `lexranked_api_read` or `edit_posts` are exempt. Headers `X-RateLimit-Limit/Remaining`. Client IPs are HMAC-hashed, never stored. |
| Versioning | Additive changes bump the minor API version; breaking DTO changes require `lexranked/v2`. |

## Authentication

Public reads need no auth. The Next.js server and workers authenticate with a
**WordPress Application Password** of a user with the least-privilege
`LexRanked API` role (`lexranked_api`), which only exempts them from rate
limits. Credentials live in server-side environment variables only.

## Endpoints

### `GET /status`
Health/version. `Cache-Control: no-store`.
```json
{ "status": "ok", "service": "lexranked-core", "pluginVersion": "0.2.0", "apiVersion": "1.1.0", "namespace": "lexranked/v1" }
```

### `GET /lawyers` · `GET /law-firms`
| Param | Description |
|-------|-------------|
| `state` | State slug (`florida`) or code (`FL`); includes cities in that state |
| `city` | City slug |
| `practice_area` | Practice area slug |
| `firm` | (lawyers only) firm ID |
| `has_score` | `true` / `false` |
| `orderby` | `score` (default), `name`, `rating`, `review_count`, `updated` |

Entities without a value for the sort field are listed last on `desc`.

### `GET /lawyers/{id|slug}` · `GET /law-firms/{id|slug}`
Detail DTO — see below. 404 `lexranked_not_found` if unpublished/missing.

### `GET /rankings`
Ranking definitions without entries. Params: `location` (state or city slug),
`practice_area`, `indexable` (bool), `orderby=updated|title`.

### `GET /rankings/{id|slug}`
Ranking with ordered `entries`. Entries are the published entities that
match the ranking's location (city, or state incl. its cities) and practice
area and have a stored score for the ranking's `scoreVersion`, ordered by
score DESC, then ID ASC. **Commercial status is never an input.** If fewer
than `minEntities` qualify, the ranking `isThin`, returns no entries and is
not `indexable`. Demo rankings are never `indexable`.

Entries come from the **latest engine run** (snapshot). Each entry has
`position`, `score`, `scoreVersion`, `movement` (places gained since the
previous run; `null` on the first run), `isNew` and `breakdown` (the seven
components with `points`, `max`, `explanation` and `missing`). The ranking has
`calculatedAt`; `updatedAt` is the later of the last edit and the last
calculation. Detail responses also include `summary`, `body`, `faq` and
`editorial` (API 1.2).

### `GET /rankings/{id|slug}/history?limit=10`
Recent runs, newest first: `{ rankingId, runs: [{ runId, calculatedAt,
scoreVersion, entries: [{ entityId, name, position, score }] }] }`.

### `GET /score-versions`
`{ active: "v1.0", versions: [{ id, weights: [{ key, label, weight }], params }] }`.
This is the single source of methodology weights for the frontend.

### `GET /states` · `GET /cities?state=` · `GET /practice-areas`
Terms with published `lawyerCount` / `lawFirmCount`. `hide_empty` (default
`true`) hides terms with no published entities, so the frontend only builds
pages that have data.

### `GET /sources`
Source registry: `{ id, name, url, type, tier, isDemo }`. Params:
`entity_id` (sources referenced by that entity's evidence), `source_type`.

### `GET /verifications`
Public verification records of published entities: `{ id, entity, type,
status, verifiedAt, expiresAt, source, sourceUrl, isDemo }`. `status` is the
*effective* status (a verified record past `expiresAt` reports `expired`).
Params: `entity_id`, `verification_type`, `status`.

### `GET /search?q=`
Name search across lawyers and firms (`q` 2–100 chars, `type=all|lawyer|law_firm`,
`per_page` ≤ 20). Sends `X-Robots-Tag: noindex`; stricter rate limit.

## DTOs

### Lawyer (summary)
```json
{
  "id": 10, "type": "lawyer", "slug": "avery-example-demo", "path": "/lawyers/avery-example-demo/",
  "name": "Avery Example (Demo)", "firstName": "Avery", "lastName": "Example", "title": "Founding Partner",
  "firm": { "id": 7, "slug": "harbor-example-injury-law-demo", "name": "Harbor Example Injury Law (Demo)", "path": "/law-firms/harbor-example-injury-law-demo/" },
  "location": { "city": "Miami", "citySlug": "miami", "state": "Florida", "stateSlug": "florida", "stateCode": "FL" },
  "practiceAreas": [{ "slug": "personal-injury", "name": "Personal Injury" }],
  "rating": 4.9, "reviewCount": 387,
  "ranking": { "score": 94.21, "scoreVersion": "demo", "calculatedAt": "2026-09-25T07:46:24Z" },
  "commercial": { "status": "free", "isPaidPlacement": false },
  "verification": { "status": "verified", "verifiedAt": "2026-09-24T07:46:24Z", "checks": { "bar_status": "verified", "identity": "verified", "license": "verified" } },
  "isDemo": true,
  "updatedAt": "2026-09-25T07:46:24Z"
}
```
(Values above are the clearly-labelled demo seed, not real data.)

`ranking` and `commercial` are sibling objects: payment never changes `ranking`.

### Lawyer / firm detail: scoring
`ranking.breakdown` (the entity-level components) and `rankings`
(`[{ id, title, path, position, score, calculatedAt, isDemo }]`, the
entity's position in the latest run of each ranking).

### Lawyer (detail) adds
`contact {website, phone}`, `address {zipCode, country}`, `professional
{yearsExperience, barState, barNumber, barStatus, education[], awards[],
languages[]}`, `bio` (sanitized HTML), `freshness {category, maxAgeDays,
lastVerifiedAt, isStale, staleAt}`, `sources[]` (evidence), `createdAt`.

### Evidence item
```json
{ "field": "bar_status", "value": "active",
  "source": { "id": 4, "name": "Example State Bar Registry (Demo)", "url": "https://example.com/demo/bar-registry", "type": "official_registry", "tier": 1 },
  "retrievedAt": "2026-09-24T07:46:24Z", "confidence": 0.99, "verificationStatus": "verified" }
```
Sorted by field, then source tier (most authoritative first), then newest.

### Law firm (detail)
Summary fields + `lawyerCount`, `contact {website, phone, email}`, `address
{street, zipCode, country}`, `lawyers[]` (lawyer summaries), `description`,
`freshness`, `sources[]`.

### Ranking (detail)
`id, slug, path, title, entityType, location, practiceArea, scoreVersion,
entryCount, minEntities, isThin, indexable, isDemo, updatedAt,
methodologyUrl, intro, entries[{ position, score, scoreVersion, entity }]`.

TypeScript definitions: `frontend/types/api.ts`.
