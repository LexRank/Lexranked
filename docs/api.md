# REST API

Namespace: `/wp-json/lexranked/v1/` · API contract version: `1.18.0`
(`X-LexRanked-API` response header).

Public endpoints are `GET`; the private research API (below) accepts `POST`
from research workers, and the claim endpoints accept `POST` from the
frontend server. Responses are stable DTOs built by pure mappers in
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
{ "status": "ok", "service": "lexranked-core", "pluginVersion": "0.4.0", "apiVersion": "1.1.0", "namespace": "lexranked/v1" }
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
Each item carries `content` (API 1.5): `{ summary, body (HTML), faq[],
reviewedBy, reviewedAt }` edited on the term, or `null`.
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

### `GET /health` (API 1.6, private)
Operational checks for monitoring; requires the `lexranked_api` role or an
administrator (401 otherwise). `{ status: ok|warning|critical, checks[{key,
status, message}], version, apiVersion, time }`; HTTP 503 when critical.
See [operations.md](operations.md#monitoring).

### `GET /articles` · `GET /articles/{id|slug}` (API 1.5)
Published editorial articles (WordPress Posts, no password). List params:
`page`, `per_page`, `orderby=date|modified|title`, `order`, `category`.
Summary: `{ id, slug, path, title, excerpt, author{name}, publishedAt,
updatedAt, reviewedBy, reviewedAt, categories[], image{url,width,height,alt}|null,
wordCount, readingMinutes, isThin (< 300 words), relatedRankingId, isDemo }`.
Detail adds `body` (sanitized HTML) and `relatedRanking {id, title, path}`.

### `GET /entities/{entity_id}` · `GET /entities/resolve?type=&slug=` (API 1.8)
Stable identities ([knowledge-base.md](knowledge-base.md)). `{ entityId,
entityType: lawyer|law_firm|location|practice_area, canonicalName, slug,
status: "active", path, createdAt, updatedAt }`. `resolve` accepts a current
**or former** slug (renames) and follows merges; unknown → 404, invalid
`type` → 400. Drafts and archived entities are 404. Lawyer, firm, state,
city and practice-area DTOs carry `entityId` (the `id` field stays the CMS
record ID).

### `GET /data-quality` (API 1.10)
The published Data Quality model: `{ version, dimensions[{key, label, weight,
description}], expected{lawyer: {attribute: weight}, law_firm: …},
sourceTierScores, summary{count, average, bands} }`. See
[knowledge-base.md](knowledge-base.md#etap-c-data-quality-score-implemented).

### `GET /market?location=&practice_area=` (API 1.16)
Statistics for a market. Both parameters are optional slugs; an unknown slug is 404.

The response is `{ scope{location{slug, name, type: state|city}|null, practiceArea{slug, name}|null}, stats, summary }`, where `stats` is:
- `version: "mkt-1.0"`;
- counts: `lawyers`, `firms`, `verifiedLawyers`, `verifiedFirms`, `demoProfiles`;
- figures: `averageRating`, `medianReviewCount`, `medianExperience`, each `{value, sample}` or `null`;
- practice areas: `mostCommonPractice{slug, name, count}|null`, `practiceAreas[]`;
- dates: `dataVerifiedAt`, `calculatedAt`;
- `notes[]`.

Rules:
- Figures use only sourced, non-conflicting lawyer facts and are `null` below a sample of 3.
- `summary` is a fixed template over these numbers.

### AI-readable pages (API 1.15)
- **`GET /methodology`** returns `{ active, versions[], updatedAt, schedule{recalculation, dataQuality, snapshots}, sourceTiers[{type, tier, tierLabel}], freshness[{category, maxAgeDays}], dataQuality, pageEligibility }`. `active` and `versions[]` are ScoreVersion objects; `updatedAt` is the last calculation.
- **Lawyer and firm details** add `aiSummary{version: "sum-1.1", text, facts[{key, label, value, status: verified|sourced|derived, asOf, source}], asOf}`. It is generated from the profile's facts only.
- **Ranking details** add `sources[{id, name, url, publisher, type, tier, tierLabel, facts, entities}]`: the sources behind the entries' facts, best tier first.

### `GET /page-eligibility` and `eligibility` on DTOs (API 1.14)
The page eligibility rules are `{ version: "pe-1.0", types: { hub | ranking | profile | article | comparison | listing: [{ key, label, required, level: exist|index, description }] } }`.

Every DTO carries its decision:
- Rankings (list and detail) carry `eligibility{exists, indexable, reasons[], version, type, checks[{key, label, value, required, level, passed}]}`. `isThin` is `!exists`, and `indexable` comes from the decision.
- States, cities, practice areas, lawyer, firm and article summaries, and comparisons carry `eligibility{exists, indexable, reasons[]}`.
- Lawyer and firm details carry the full decision.

### Contextual rankings (API 1.13)
Rankings carry `context`: `null` for an ordinary ranking, otherwise:
- `type`: `case_type` | `client_type` | `language`;
- `value`, `segment`, `label`;
- `attribute`: `case_types` | `client_types` | `languages`;
- `eligibility{eligible, reasons[], qualified, verified, parentCount, minEntities, minVerified}`;
- `calculatedAt`;
- `parent{id, title, path}`.

The `path` includes the segment, e.g. `/rankings/florida/miami/personal-injury/car-accidents/`. A context below its threshold has `isThin: true` and no entries.

Entries carry:
- `keyFacts{yearsExperience, barStatus, practiceAreas[], awards}`: the scored inputs;
- in contextual rankings, `qualification{attribute, value, status: verified|unverified, sourceId, claimId, observedAt, verifiedAt, source{name, url, tierLabel}}`.

Lawyer `professional` and firm detail add `caseTypes[]` and `clientTypes[]`.

### `GET /compare?type=lawyer|law_firm&entities=12,34` (API 1.12)
Side-by-side comparison of 2–4 published entities of one type, by stable
entity ID (merges followed). `{ version: "cmp-1.0", type, entities[{id,
entityId, type, name, path, location, firm, verification, isDemo}],
rows[{key, label, group, kind: number|text|list|objects, cells[{id, value,
display, status: verified|unverified|conflict|missing|directory|derived,
source{name, publisher, tierLabel, url}|null, checkedAt, isStale, note}],
highest[id], shared[]|null, note}], sharedRankings[{id, title, path,
calculatedAt, positions[{id, position, score}]}], summary[], basis }`.
`highest` is set only on numeric rows where every value is on record, none
conflicts and one entity is strictly ahead (scores: same methodology version
only). Commercial data is never included. Unknown/unpublished/wrong type →
404; fewer than 2 or more than 4 distinct IDs → 400. Profile `rankings[]`
entries carry `neighbors[{id, entityId, name, position}]` since 1.12.

### `GET /attributes` (API 1.9)
The data dictionary: `{ attributes: [{ key, label, valueType, entityTypes[],
category, layer: fact|derived, freshness, unit, description }] }`.

### `GET /placements` (API 1.7)
Labelled paid placements for **one page**, delivered separately from organic
data. `product=sponsored&ranking=<id>` (a published ranking) or
`product=featured` with exactly one of `location=<slug>` / `practice_area=<slug>`.
Returns `[{ id, product, label, isPaidPlacement: true, disclosure, entity }]`
(`entity` = lawyer or firm summary), live placements only, eligible profiles
only, at most `max_sponsored_per_ranking` / `max_featured_per_page`, oldest
booking first. Rankings never embed placements. See [commercial.md](commercial.md).

### `POST /claims` · `POST /claims/confirm` (API 1.7, frontend server only)
Require `lexranked_submit_claims` (the `lexranked_api` role); 401 otherwise.
`POST /claims` body `{ entityType, entityId, name, email, phone?, role:
self|firm_representative, barState?, barNumber?, message?, consent: true }` →
**202** `{ status: "pending_email" }` (also for repeats: nothing reveals whether
a profile is claimed or an email known). 400 `lexranked_invalid_claim` with
`data.field`, 404 unknown profile, 429 `lexranked_claim_limit` (3 per email /
10 per profile per day), 503 when claims are switched off.
`POST /claims/confirm` `{ token }` → `{ status: "pending_review" }`; 400 for
an unknown, used or expired token. Responses are `no-store`.

### Client reviews (API 1.20)
`POST /reviews` · `POST /reviews/confirm` (frontend server only, same role as
claims). Body `{ entityType, entityId, rating: 1–5, title?, body (40–2000
chars, no links), name, email, serviceYear, client: true }` → **202**
`{ status: "pending_email" }` (also for repeats: one review per email and
profile). 400 `lexranked_invalid_review` with `data.field`, 404 unknown
profile, 429 (5 per email / 20 per profile per day). The confirmation link
goes to the frontend `/reviews/confirm/?token=…`; confirming moves the review
to `pending_review`.
`GET /editorial/reviews?status=` and `POST /editorial/reviews/{id}`
`{ action: approve|reject, note? }` (editors, `edit_posts`). The reviewer's
email is deleted when a review is moderated.
Lawyer and firm details carry `clientReviews{count, average, items[{id, rating,
title, body, author, serviceYear, publishedAt}]}` (approved reviews only;
author is first name and last initial). Approved reviews are also recorded as
`rating` and `review_count` evidence from the "LexRanked client reviews" source
(type `lexranked_reviews`, tier 5), which the ranking engine scores like other
review data; review data from a higher-tier platform takes precedence.

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
  "commercial": { "status": "free", "isPaidPlacement": false, "claimed": false, "premium": false },
  "verification": { "status": "verified", "verifiedAt": "2026-09-24T07:46:24Z", "checks": { "bar_status": "verified", "identity": "verified", "license": "verified" } },
  "isDemo": true,
  "updatedAt": "2026-09-25T07:46:24Z"
}
```
(Values above are the clearly-labelled demo seed, not real data.)

`ranking` and `commercial` are sibling objects: payment never changes `ranking`.
Since API 1.7 `commercial.status` is derived (never typed in): `free`,
`claimed` (approved claim) or `premium` (approved claim + live premium
placement). `isPaidPlacement` is kept for compatibility and is always `false`
on profiles; featured and sponsored placements come from `GET /placements`.

### Lawyer / firm detail: scoring
`ranking.breakdown` (the entity-level components) and `rankings`
(`[{ id, title, path, position, score, calculatedAt, isDemo }]`, the
entity's position in the latest run of each ranking).

### Lawyer (detail) adds
`summary` (API 1.5, plain text), `dataQuality` (API 1.10: `{ score (0–100),
version, dimensions[{key, label, weight, score, detail}], missing[],
unsourced[], stale[], conflicts[], calculatedAt } | null`. It describes the
documentation and is **not** a ranking input), `facts[]` (API 1.9: `{ attribute, label,
category, value (normalised), unit, status: verified|unverified|conflict,
confidence, source {id, name, publisher, url, type, tier, tierLabel},
claimCount, observedAt, verifiedAt, freshness {category, maxAgeDays,
lastVerifiedAt, isStale, staleAt}, method }`), `premiumContent {label, message, ctaUrl,
disclosure} | null` (API 1.7; paid, labelled, never evidence), `contact {website, phone}`, `address {zipCode, country}`, `professional
{yearsExperience, barState, barNumber, barStatus, education[], awards[],
languages[]}`, `bio` (sanitized HTML), `freshness {category, maxAgeDays,
lastVerifiedAt, isStale, staleAt}`, `sources[]` (evidence), `createdAt`.

### Evidence item
```json
{ "field": "bar_status", "value": "active",
  "source": { "id": 4, "name": "Example State Bar Registry (Demo)", "url": "https://example.com/demo/bar-registry", "type": "official_registry", "tier": 1 },
  "retrievedAt": "2026-09-24T07:46:24Z", "confidence": 0.99, "verificationStatus": "verified", "method": "seed" }
```
Sorted by field, then source tier (most authoritative first), then newest.
`normalizedValue` (API 1.9) is the value after normalisation; `value` stays raw.
`method` (API 1.4): `manual`, `seed`, `structured_data` or `ai` (quote-checked
extraction; see [ai.md](ai.md)). Only editor-approved evidence is public.

### Law firm (detail)
Summary fields + `lawyerCount`, `contact {website, phone, email}`, `address
{street, zipCode, country}`, `lawyers[]` (lawyer summaries), `description`,
`freshness`, `sources[]`, `premiumContent` (API 1.7).

### Ranking (detail)
`id, slug, path, title, entityType, location, practiceArea, scoreVersion,
entryCount, minEntities, isThin, indexable, isDemo, updatedAt,
methodologyUrl, intro, entries[{ position, score, scoreVersion, movement, isNew,
breakdown, why, change, entity }]`.

`why` (API 1.11): `{ summary, strengths[{key, label, points, max, average}],
gaps[…], behind {position, entityId, name, scoreGap, components[{key, label,
delta}]} | null, missing[] }`. `change` (API 1.11, null when nothing changed):
`{ previousPosition, previousScore, scoreDelta, reasons[{type:
entered|methodology|component|input|competitor|left, text, …}] }`. Both are
computed from stored snapshots, never by AI. `GET /score-versions` versions
carry `input: profile|facts`.

TypeScript definitions: `frontend/types/api.ts`.

## Research API (private)

Since API 1.3.0. Requires the `lexranked_research` capability (role
**LexRanked Research Worker**, or administrators); anonymous → 401, the
`lexranked_api` role → 403. Never cached (`private, no-store`). Endpoints
that act on a job's data require the lease token from `claim` in the
`X-LexRanked-Lease` header; without a valid lease → `409
lexranked_lease_lost`, cancelled job → `409 lexranked_job_cancelled`.
Batch endpoints accept 1–100 `items` and answer per item (`{index, error:
{field, message}}` for rejected items) — one bad item never fails a batch.
See [research.md](research.md) for the semantics.

| Method & path | Body / params | Returns |
|---|---|---|
| `POST /research/jobs` | `{job_type: candidate_discovery\|source_refresh, params?, title?, locations?[slug], practice_areas?[slug]}` | Job (`pending`); `403` unless **Autonomous research** is on; `candidate_discovery` needs `params.dataset` (API 1.17) |
| `POST /research/jobs/claim` | `{worker, types[]}` | `{job: Job + token}` or `{job: null}`; `503 lexranked_claim_busy` when another claim holds the lock |
| `GET /research/jobs/{id}` | — | Job + `logCounts`, `candidateCounts` |
| `GET /research/jobs/{id}/log` | `after` (log ID) | `[{id, level, stage, message, context, createdAt}]` |
| `POST /research/jobs/{id}/heartbeat` 🔒 | `{cursor?, processed_count?, stats?, logs?[]}` | Job (lease extended) |
| `POST /research/jobs/{id}/complete` 🔒 | same as heartbeat | Job (`completed`; ranking recalculation scheduled) |
| `POST /research/jobs/{id}/fail` 🔒 | `{error, retryable=true, …progress}` | Job (`failed`, `nextRetryAt` or final) |
| `GET /research/jobs/{id}/targets` 🔒 | `after` (entity ID), `limit` ≤ 100 | `[{id, entityType, status, name, website}]` in the job's scope |
| `POST /research/jobs/{id}/sources` 🔒 | `items[{url, source_type, title?}]` | `[{index, sourceId, created, tier}]` |
| `POST /research/jobs/{id}/candidates` 🔒 | `items[{entity_type, name, source_url, source_type, city?, state?, practice_area?, website?, payload?}]` | `[{index, candidateId, created, status, entityId, entityType, reason}]` — `entityId` only for `matched`/`created` |
| `POST /research/jobs/{id}/claims` 🔒 | `items[{entity_id, field_name, value, source_url and/or source_id, source_type, retrieved_at, confidence, method?: seed\|structured_data\|ai}]` (`ai` needs AI enabled; confidence capped at 0.6) | `{results[{index, claimId, duplicate}], applied{entityId: fields[]}, review[entityIds]}` |
| `POST /research/jobs/{id}/verifications` 🔒 | `items[{entity_id, verification_type, status, source_url, source_type, source_id?, notes?}]` | `[{index, verificationId, status, downgraded, duplicate}]` |
| `GET /research/jobs/{id}/review-candidates` 🔒 | `after`, `limit` | candidates in review + suggested profile (API 1.4) |
| `POST /research/jobs/{id}/auto-publish` 🔒 | — | finish automatic publication of a completed job (idempotent: published profiles are left alone; missing sources, records and rankings are added) (API 1.19) |
| `POST /research/jobs/{id}/candidate-notes` 🔒 | `items[{candidate_id, verdict: same\|different\|unsure, confidence, reason, model}]` | advisory AI notes; `[{index, candidateId, stored}]` (API 1.4, needs AI enabled) |
| `POST /research/jobs/{id}/content-drafts` 🔒 | `{content_type: "ranking_content", target_id, content{summary, sections[{heading, paragraphs[{text}]}], faq[{question, answer}]}, facts[{id,label,value}], qa{status, issues[]}, model, prompt_version}` | `{draftId, qaStatus, updated}`; stored as a WordPress draft; QA status recomputed (API 1.4, needs AI enabled) |
| `GET /research/candidates` | `status?`, `page`, `per_page` | Candidate list (+ `X-WP-Total`) |
| `POST /research/candidates/{id}/resolve` | `{action: match\|create\|reject\|needs_review, entity_id?, reason?}` | Candidate result |

🔒 = requires `X-LexRanked-Lease`.

Job DTO: `{id, title, jobType, status, params, scope{locations[], practiceAreas[]},
cursor, processedCount, retryCount, stats, startedAt, completedAt, lockedUntil,
nextRetryAt, worker, error}`. The lease token is returned only by `claim`.

## Editorial API (private)

Since API 1.18.0. What an editor does in wp-admin, over the API: page text,
never scores, positions, facts or verification. Requires a logged-in user
with `edit_posts`; each route also checks the capability of its admin screen
(`edit_post` on the ranking or profile, `manage_categories` for hubs) and
every change is written to the audit log.

| Route | Body | Result |
|---|---|---|
| `GET /editorial/rankings/{id}` | — | `{id, title, slug, status, summary, body, faq[], reviewedBy, reviewedAt, generated}` (raw body; `generated`: text is generated and follows recalculations, API 1.21) |
| `POST /editorial/rankings/{id}` | any of `title`, `slug`, `summary`, `body` (HTML, sanitized with `wp_kses_post`), `faq[{question, answer}]` (≤ 20), `reviewed_by`, `reviewed_at` (`YYYY-MM-DD`) | the ranking's editorial view; `""` clears a field |
| `POST /editorial/rankings/{id}/generate` (API 1.21) | — | replaces the text with text generated from the ranked lawyers' facts and the state knowledge pack (see docs/content-plan.md); `422 lexranked_no_content` when complete text cannot be written. Saving text by hand turns generation off again |
| `GET /editorial/terms/{location\|practice-area}/{id}` | — | `{id, taxonomy, name, slug, summary, body, faq[], reviewedBy, reviewedAt}` |
| `POST /editorial/terms/{location\|practice-area}/{id}` | any of `summary`, `body`, `faq`, `reviewed_by`, `reviewed_at` | the hub's editorial view |
| `POST /editorial/profiles/{id}` | `{summary}` (2–4 plain sentences) | `{id, name, summary}` |
| `GET /editorial/drafts` | `qa_status?` | AI content drafts with `contentType`, `qaStatus`, `target`, `summary`, `faq`, `body`, `qaReport`, `canApply` |
| `POST /editorial/drafts/{id}/apply` | `{acknowledge?}` | `{id, status: applied\|partial\|already, errors, article_id}`; `409 lexranked_needs_review` for a draft that failed QA unless `acknowledge: true` |

Saving runs the same hooks as wp-admin, so the public page is revalidated.
