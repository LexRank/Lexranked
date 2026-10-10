# Data model

> Status: entities, taxonomies, evidence, verification (Phase 2), and score
> calculation with ranking snapshots (Phase 4) are implemented. Research
> execution (Phase 5) is still to come.

## Principles

1. **Facts are structured fields**, never only prose in a biography.
2. **Every important fact is traceable** to one or more evidence records.
3. **Unknown beats guessed.** A field with no reliable source stays `null`.
4. **Organic ranking and commercial status are separate structures.**
5. Every entity has `created_at`, `updated_at`; every important fact has `last_verified_at`.

## Storage mapping (WordPress)

| Entity | Storage | Notes |
|--------|---------|-------|
| Lawyer | CPT `lr_lawyer` + registered meta | |
| Law firm | CPT `lr_law_firm` + meta | |
| Ranking | CPT `lr_ranking` + meta | Definition (location × practice area × score version) |
| Location | Taxonomy `lr_location` (hierarchical: state → city) | `state_code`, geo meta on terms |
| Practice area | Taxonomy `lr_practice_area` | |
| Source | CPT `lr_source` | Source registry incl. tier |
| Verification record | CPT `lr_verification` | |
| Research job | CPT `lr_research_job` | Cursor for resumability |
| Evidence claim | Custom table `{prefix}lr_claims` | High volume, append-mostly (implemented) |
| Ranking snapshot | Custom table `{prefix}lr_ranking_snapshots` | Append-only runs with components + inputs (implemented) |
| Audit log | Custom table `{prefix}lr_audit_log` | Append-only (implemented) |
| Editorial article | Core `post` | |
| Fact layer | Custom table `{prefix}lr_facts` | One normalised value per entity × attribute (Etap B) |
| Entity registry | Custom tables `{prefix}lr_entities`, `{prefix}lr_entity_aliases` | Stable IDs, names and slug history (Etap A) |
| Profile claim | Custom table `{prefix}lr_profile_claims` | Private claimant data (Phase 9) |
| Placement | Custom table `{prefix}lr_placements` | Paid products (Phase 9) |

CPT slugs are prefixed `lr_` to avoid collisions (≤ 20 chars, WP limit).

### How fields are stored (Phase 2)

- Every entity's fields are declared once in its `PostTypes/*` class as
  `Schema\Field` objects. That single list drives meta registration, the
  admin form, sanitization (`FieldSanitizer`), storage (`MetaCodec`) and the
  DTO mapping.
- Meta keys are `_lr_<field>` (underscore = hidden from the generic Custom
  Fields box). Lists are JSON. **Empty input deletes the meta row** - unknown
  stays unknown, it is never stored as a guess or an empty string.
- Post title = lawyer full name / firm name; post content = bio/description.
- `city`, `state`, `state_code` come from the assigned **Location** term
  (state term → city child term; state terms carry a validated USPS code).
  `practice_areas[]` come from the **Practice Area** taxonomy.
- `lawyer_ids[]` of a firm are derived from lawyers whose `firm_id` points to it.
- `verification_status` and `last_verified_at` are **derived at read time**
  from verification records (`VerificationPolicy`), so expiry is always
  evaluated against the current time rather than a stale stored flag.
- `score`, `score_version`, `score_calculated_at` are read-only in the admin;
  only the ranking engine (Phase 4) or trusted tooling may write them.
- `created_at` / `updated_at` are the post's GMT dates.

## Lawyer

| Field | Type | Notes |
|-------|------|-------|
| id | int | WP post ID |
| slug | string | unique, `^[a-z0-9]+(-[a-z0-9]+)*$` |
| first_name, last_name, full_name, title | string | |
| firm_id | int? | → Law firm |
| city, state, state_code, zip_code, country | string | `state_code` ISO 3166-2:US suffix (`FL`) |
| website, phone, email | string? | email is **private** unless explicitly published |
| practice_areas[] | term IDs | |
| years_experience | int? | derived from bar admission date when sourced |
| rating | decimal(3,2)? | 0-5 |
| review_count | int? | |
| bar_state, bar_number, bar_status | string? | |
| education[], awards[], languages[] | structured arrays | each item may carry evidence |
| bio | text | prose only; facts must also exist as fields |
| score | decimal(5,2)? | organic score |
| score_version | string? | e.g. `v1.0` |
| ranking_position | int? | per ranking, stored in snapshots |
| verification_status | enum (derived) | `unverified`, `pending`, `verified`, `failed`, `expired` |
| last_verified_at | datetime? (derived) | oldest `verified_at` among required checks |
| commercial_status | enum | `free` … `premium`; separate from ranking |
| is_demo | bool | mock data flag; exposed as `isDemo` |
| created_at, updated_at | datetime | |

## Law firm

`id, slug, name, website, phone, email, address, city, state, zip_code,
country, practice_areas[], lawyer_ids[], rating, review_count, score,
score_version, verification_status, last_verified_at, created_at, updated_at`.

## Evidence claim

| Field | Type |
|-------|------|
| claim_id | bigint PK |
| entity_id | int |
| entity_type | `lawyer` \| `law_firm` |
| field_name | string (whitelisted) |
| value | JSON |
| source_id | int → Source |
| source_url | string |
| source_type | string (configurable taxonomy) |
| retrieved_at | datetime |
| confidence | decimal(4,3) 0-1 |
| verification_status | `pending` \| `verified` \| `failed` \| `expired` |
| claim_hash | sha1(entity, field, value, source) - unique; same fact from the same source is one claim |
| job_id | research job that produced it (0 = editor / seed) |
| review_status | `approved` (public) \| `pending_review` (research evidence about a published entity, hidden) \| `rejected` |
| method | `manual` \| `seed` \| `structured_data` \| `ai` (schema v5) |

`ClaimValidator` rejects any claim without a source (URL or registered
source), with an unconfigured source type, a confidence outside 0-1, or a
field that is not traceable (system and commercial fields such as `score`
or `commercial_status` can never be "evidenced").

Field-value resolution (`FactResolver`) picks the claim with the best
(source tier, verification status, recency, confidence, ID). Ties never
resolve by guessing; conflicting claims at the best tier/status are flagged
for review. Only `approved` claims are public or used by the ranking engine.

## Source tiers (configurable)

| Tier | Default meaning |
|------|-----------------|
| 1 | Official government / bar / regulatory source |
| 2 | Official lawyer or firm website |
| 3 | Reputable professional directory |
| 4 | Review platform |
| 5 | Secondary source |

Tiers are configuration stored in the database; no third-party source is
hard-coded as authoritative.

## Verification record

`verification_type` (`identity`, `business`, `location`, `website`,
`license`, `bar_status`, `practice_area`, `review_data`), `status`
(`pending`, `verified`, `failed`, `expired`), `source`, `verified_at`,
`expires_at`, `verified_by`, `notes`. A profile is "verified" only when its
required verification types are `verified` and unexpired. Required types are
configurable (Settings); defaults: lawyers `identity, license, bar_status`,
firms `business, website`. Any required `failed` → profile `failed`; any
required `expired` → `expired`; otherwise `pending` if a check is pending,
else `unverified`.

## Research job

`job_id, job_type, location, practice_area (scope terms), status
(pending|running|completed|failed|cancelled), params (JSON), cursor,
processed_count, stats (JSON), started_at, completed_at, retry_count,
next_retry_at, locked_until, worker, error_message, created_at`; the lease
token is private post meta. See [research.md](research.md) for leases,
retries and resumption. Admins may move failed → pending (manual retry) or
cancel any job. Administrator-only (custom capability type `lr_research_job`).

## Research candidate (`lr_candidates`)

`candidate_id, dedupe_key (unique), job_id, entity_type, name,
normalized_name, city, state, practice_area, website, source_url, status
(new|matched|created|needs_review|rejected), entity_id, match_confidence,
reason, payload, ai_note (advisory AI verdict, schema v5), created_at,
updated_at`. Internal research data - never in the public API.

## AI content draft (`lr_content_draft`)

Post (always `draft`) with `content_type` (`ranking_content`,
`hub_content`, `profile_summary`, `article`), `target_id` (ranking, lawyer,
firm; for articles the optional related ranking), `target_term` +
`target_taxonomy` (hubs), `article_id` (post created on apply), `qa_status` (`needs_review` \| `ready_for_review` \| `applied`),
`summary`, body (post content, built from escaped plain text), `faq`,
`qa_report` (JSON issues), `facts` (JSON, the numbered facts the model was
given), `model`, `prompt_version`, `job_id`, `applied_at`. Never public;
applied to its ranking only by an editor. See [ai.md](ai.md).

## Research log (`lr_research_log`)

`id, job_id, level (debug|info|warning|error), stage, message (≤500),
context (JSON, secrets redacted), created_at`.

## Editorial article (core `post`)

Standard WordPress post (title, content, excerpt, featured image, author,
categories) plus `related_ranking`, `reviewed_by`, `reviewed_at`, `is_demo`
(`_lr_*` meta). Served by `/articles`.

## Hub content (term meta on locations and practice areas)

`_lr_summary`, `_lr_body` (sanitized HTML), `_lr_faq` (JSON list),
`_lr_reviewed_by`, `_lr_reviewed_at`.

## Ranking snapshot

`snapshot_id, run_id, ranking_id (0 = entity-level), entity_id, entity_type,
position, score, score_version, context, components, inputs, calculated_at`.
See docs/ranking-methodology.md.

## Entity (Etap A)

Every lawyer, law firm, location and practice area is an **entity** with a
stable `entity_id` in `lr_entities` (`entity_type, canonical_name, slug,
status: active|draft|archived|merged, wp_object: post|term, wp_id,
merged_into, created_at, updated_at`). WordPress holds the content; the
registry holds identity. Renames keep the ID and add the former name and slug
to `lr_entity_aliases` (`alias_type: name|slug, value, normalized,
is_current, first_seen, last_seen`). IDs are never reused. See
`docs/knowledge-base.md`.

## Facts and attributes (Etap B)

- `lr_claims` also stores `lr_entity_id` (registry ID) and `value_normalized` (the raw value stays in `value`).
- `lr_facts`: `lr_entity_id, entity_type, wp_id, attribute, value (normalised JSON), status (verified|unverified|conflict), confidence, source_tier, claim_id, source_id, claim_count, observed_at, verified_at, computed_at`, unique per entity and attribute.
- Attributes are defined in code (`Attribute\Attributes`) and published at `GET /attributes`.
- Sources gain `publisher, retrieved_at, last_checked_at, status`.
- Etap C: `lr_entities` also stores `quality_score, quality_json, quality_at` (the Data Quality Score; not a ranking input).

See `docs/knowledge-base.md`.

## Commercial data (Phase 9)

**Never** read by the ranking engine (enforced by a test). See `docs/commercial.md`.

- **Profile claim** (`lr_profile_claims`): `entity_id, entity_type, status`
  (`pending_email → pending_review → approved | rejected`, or `expired`),
  claimant `name, email, phone, role` (`self | firm_representative`),
  `bar_state, bar_number, message`, `email_token_hash` (SHA-256; the token
  itself is never stored), `email_token_expires, email_verified_at`,
  `identity_method` (how the editor checked identity; required to approve),
  `review_note, reviewed_by, reviewed_at, personal_data_purged`. Personal data
  of rejected / expired claims is erased after 30 days.
- **Placement** (`lr_placements`): `entity_id, entity_type, product`
  (`premium | featured | sponsored`), page scope (`ranking_id` for sponsored;
  `location_term_id` **or** `practice_area_term_id` for featured),
  `starts_at, ends_at` (UTC, end exclusive, ≤ 366 days), `status`
  (`active | paused | cancelled`), `premium_message, cta_url` (premium only),
  private `order_ref, notes`, `created_by`.
- **`commercial_status`** (profile meta, read-only): derived from the two
  tables - `free`, `claimed` or `premium`. Display only. The legacy values
  `verified`, `featured`, `sponsored` are no longer assigned to profiles.

## Freshness rules (configurable defaults)

| Data | Max age |
|------|---------|
| Bar status | 30 days |
| Review data | 7 days |
| Website | 30 days |
| General profile | 90 days |

Configured in **LexRanked › Settings**; unknown categories fall back to `profile`.

## Demo data

`wp lexranked seed-demo` creates clearly-labelled mock records (names end in
"(Demo)", `example.com` URLs, fictional 555-01xx phones, `DEMO-` bar
numbers, score version `demo`, `is_demo = true`). They are exposed as
`isDemo: true`, demo rankings are never indexable, and
`wp lexranked purge-demo` removes them.
