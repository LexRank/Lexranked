# Data model

> Status: **design** (Phase 1). Implemented in Phase 2 (entities), Phase 4
> (ranking), Phase 5 (evidence/research).

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
| Evidence claim | Custom table `{prefix}lr_claims` | High volume, append-mostly |
| Ranking snapshot | Custom table `{prefix}lr_ranking_snapshots` | Append-only history |
| Audit log | Custom table `{prefix}lr_audit_log` | Append-only |
| Editorial article | Core `post` | |

CPT slugs are prefixed `lr_` to avoid collisions (≤ 20 chars, WP limit).

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
| rating | decimal(3,2)? | 0–5 |
| review_count | int? | |
| bar_state, bar_number, bar_status | string? | |
| education[], awards[], languages[] | structured arrays | each item may carry evidence |
| bio | text | prose only; facts must also exist as fields |
| score | decimal(5,2)? | organic score |
| score_version | string? | e.g. `v1.0` |
| ranking_position | int? | per ranking, stored in snapshots |
| verification_status | enum | `unverified`, `pending`, `verified`, `failed`, `expired` |
| last_verified_at | datetime? | |
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
| confidence | decimal(4,3) 0–1 |
| verification_status | `pending` \| `verified` \| `failed` \| `expired` |

Field-value resolution picks the claim with the best (source tier,
verification status, recency, confidence). Ties never resolve by guessing;
conflicting high-tier claims are flagged for review.

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
required verification types are `verified` and unexpired.

## Research job

`job_id, job_type, location, practice_area, status (pending|running|completed|failed|cancelled),
cursor, processed_count, started_at, completed_at, retry_count, error_message, created_at`.

## Ranking snapshot

`ranking_snapshot_id, ranking_id, entity_id, position, score, score_version, calculated_at`.

## Commercial status (Phase 9)

Separate record per entity: `status` ∈ `free, claimed, verified, featured,
sponsored, premium`, with billing metadata. **Never** read by the score
calculator.

## Freshness rules (configurable defaults)

| Data | Max age |
|------|---------|
| Bar status | 30 days |
| Review data | 7 days |
| Website | 30 days |
| General profile | 90 days |
