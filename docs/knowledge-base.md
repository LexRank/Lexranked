# From ranking site to professional-services knowledge base

LexRanked is moving from *ranking website + profiles + AI content* to a
**structured knowledge base** that collects, normalises, verifies and
orders facts about lawyers, and only then derives rankings, comparisons and
text from them. This document maps the target architecture onto what exists
and sets the implementation order. It extends the existing architecture: nothing is
rebuilt from scratch.

```
RESEARCH → ENTITY DISCOVERY → ENTITY RESOLUTION → SOURCE COLLECTION → FACT EXTRACTION
→ CLAIMS → VERIFICATION → NORMALISATION → DERIVED METRICS → {DATA QUALITY, RANKING ENGINE}
→ PAGE ELIGIBILITY → PUBLIC PAGE → {SUMMARY, COMPARE, SOURCES} → AI INTERPRETATION
```

Data flows one way: **data → evidence → ranking → page → AI summary**. AI
never researches from memory, never invents facts and never decides a
position. Payment never enters the organic path (docs/commercial.md).

## Layers

| Layer | Meaning | Where |
|---|---|---|
| Entity | a thing we describe, with a stable `entity_id` | `lr_entities` (Etap A ✅) |
| Attribute | a named property with a type (bar_status, years_experience, practice_area …) | field schemas per type; attribute registry in Etap B |
| Claim / evidence | entity + attribute + value + source + date + confidence + verification | `lr_claims` (Phase 2/5) |
| Source | a document we read, with type, tier and check dates | `lr_source` posts (extended in Etap B) |
| Raw → normalised → derived → interpretation | `review_count = 387` → `387` → `review_strength = 91.4` → "strong review profile" | claims → entity fields → score components → AI text |
| Score | versioned, component-based, snapshotted | `lr_ranking_snapshots` (Phase 4) |
| Ranking | a function over entities in a context | `lr_ranking` + engine |
| Comparison | a function over two or more entities | Etap E |

## Status of each change (1–46)

✅ done · ◐ partly there · ○ planned (stage)

| # | Change | Status | Notes |
|---|---|---|---|
| 1–2 | Entity as the primary object; types LAWYER, LAW_FIRM, LOCATION, PRACTICE_AREA | ✅ A | `lr_entities`: stable `entity_id`, `entity_type`, `canonical_name`, `slug`, `status`, `created_at`, `updated_at`. Identity never depends on the name (below) |
| 3 | Attributes linkable to evidence | ◐ B | Each entity type has one field schema, and evidence claims reference its fields. Etap B adds an attribute registry (type, unit, applicable entity types, which layer) |
| 4 | Claim / evidence layer | ✅ / ◐ B | `lr_claims` already stores entity, field, value, source, `retrieved_at`, confidence, verification and review status, method and research job. Etap B keys claims by `entity_id` and covers term entities |
| 5 | Sources as objects with tiers | ◐ B | Source posts have URL, type and tier (1–5, configurable). Etap B adds domain, publisher, `last_checked_at`, status and the requested source types (directory, editorial, social, other) |
| 6 | Data vs interpretation | ◐ B/J | Raw claims and derived components are already separate, and AI text cites facts. Etap B makes the four layers explicit |
| 7 | Ranking from evidence, never from AI | ◐ B | The engine reads resolved entity fields (from claims via FactResolver), never AI output. AI claims are capped at 0.6 and need review. Etap B makes the path claims → verified facts → normalised attributes explicit |
| 8 | Score components | ✅ | 7 components with points, maximum, explanation and missing inputs, plus `score_version` and `calculated_at`, stored per snapshot |
| 9 | "Why this ranking / why ranked here" | ◐ D | Methodology section and per-entry breakdown exist. Etap D renders a per-entity "why ranked here" from components |
| 10, 25 | Comparison engine and pages | ○ E | `/compare?lawyer=…&lawyer=…`, noindex, structured data only |
| 11–13 | Contextual ("best for") rankings, context model, context URLs | ○ F | `case_type`, `client_type`, `language` qualifiers; `/rankings/{state}/{city}/{practice}/{context}/` only when eligible |
| 14–15 | Page eligibility engine, no thin programmatic SEO | ◐ G | Rules exist: hubs need 3 published lawyers and 3 real ones to index; rankings need 5 entries; articles need 300 words; demo is noindex. Etap G moves this to one backend engine with verified-count, evidence-coverage and uniqueness thresholds |
| 16–18 | Profile structure, per-fact freshness, source panel | ◐ H | Profiles show identity, firm, areas, credentials, score breakdown, rankings, verification, and evidence with source, tier and retrieval date. Etap H groups them per fact ("Bar status → Florida Bar → verified Sep 27") |
| 19 | AI-readable structured summary | ◐ H | Ranking pages have an answer-first summary built from data. Profiles and hubs get one in Etap H |
| 20 | Schema.org from entity data only | ✅ / ◐ H | Person, Organization / LegalService, ItemList, BreadcrumbList and FAQPage are emitted only when their data exists. There is no review markup |
| 21–24 | Directory-grade ranking layout, key attributes on cards, `ContextualAttributes`, data-driven related questions | ◐ H | The layout already follows answer → methodology → ranking → FAQ → related. Contextual attributes and generated questions depend on Etap F |
| 26 | Ranking snapshots | ✅ | Every calculation is an immutable run; `/rankings/{id}/history` |
| 27 | Explaining position changes from snapshot diffs | ◐ D | Movement is known. Etap D diffs components between runs ("review data changed, competitor gained") |
| 28 | Research pipeline discover → … → update rankings | ◐ B | Discover, match, sources, extract, claims, verify, resolve into drafts and recalculate all exist (Phase 5). Normalisation and metrics become explicit in B |
| 29–30 | Entity resolution with identifiers; AI only advisory | ◐ A/B | The deterministic matcher uses name, name key, domain and city, and now **former names** (A). Etap B adds phone, address, email and bar-number signals. AI stays a note to the reviewer |
| 31–32 | Data Quality Score, never a hidden boost | ◐ C | Today "data quality" is an open 5-point component. Etap C adds a separate, displayed Data Quality % (completeness, freshness, source quality, verification coverage, consistency) that is not a ranking |
| 33 | Coverage statistics | ◐ I | Hubs show counts. Verified-profile counts come in Etap I |
| 34–35 | Market statistics computed by the backend | ○ I | Average rating, median reviews, counts and most common practice, computed and timestamped |
| 36 | Research provenance | ◐ B | Claims and candidates carry `job_id`; the job log exists; snapshots store the exact inputs. Etap B links fact → claim → source → job end-to-end |
| 37–38 | AI interpretation layer | ◐ J | AI already writes only from numbered facts with QA, into drafts. Etap J reframes it as interpretation (summarise, explain, compare, classify) |
| 39–40 | Semantic internal linking | ◐ H | Links follow the data (profile → firm, city, state, areas, rankings, related lawyers). Etap H adds market statistics and comparisons |
| 41–42 | Live methodology, score versioning | ✅ / ◐ | The methodology page reads versions and weights from the API; old snapshots keep their version. Data sources and update frequency are added in H |
| 43 | Benchmark structure, not competitors' text | rule | Structure, coverage, freshness, entity depth and transparency are benchmarked; no competitor content or design is copied |
| 45 | What not to do | rule | No mass pages, no bulk AI articles, no auto-publishing, no fake reviews, no paid ranking, no LLM facts or ranks |

## Implementation order

| Stage | Scope | Status |
|---|---|---|
| **A** | Entity model | ✅ this change |
| B | Attributes, claims keyed by entity, source objects, fact layers, resolution identifiers, provenance | next |
| C | Data Quality Score | |
| D | Per-entity "why ranked here", snapshot-diff explanations | |
| E | Comparison engine | |
| F | Contextual rankings | |
| G | Unified page eligibility engine | |
| H | AI-readable page architecture | |
| I | Market statistics and coverage | |
| J | AI interpretation layer | |

## Etap A: entity model (implemented)

- **`lr_entities`**: `entity_id` (own sequence, never reused), `entity_type`
  (`lawyer`, `law_firm`, `location`, `practice_area`), `canonical_name`,
  `slug`, `status` (`active`, `draft`, `archived`, `merged`),
  `wp_object` + `wp_id` (the WordPress post or term holding the content),
  `merged_into`, `created_at`, `updated_at`.
- **Identity is not the name.**
  - A rename or slug change updates the row and keeps the ID.
  - The former name and slug are kept in **`lr_entity_aliases`**, as current or former aliases with first/last seen.
  - Trashing or deleting archives the entity; the ID stays reserved and returns when the profile is restored.
  - `merged_into` is reserved for entity resolution (Etap B): resolving follows it to the surviving entity.
- **Sync**: WordPress hooks keep the registry current (post saves and deletes, term create/edit/delete). The schema-v7 migration registers everything that already exists (`wp lexranked entities --backfill` does the same on demand).
- **API 1.8**:
  - `entityId` on lawyer, firm, state, city and practice-area DTOs;
  - `GET /entities/{entity_id}`;
  - `GET /entities/resolve?type&slug` (current or former slug).
- **Frontend**: a request for a former slug gets a **308** to the entity's current page (profiles, states, cities, practice areas), so links and search results survive renames.
- **Research**: the candidate matcher also matches **former names**, so "Smith Law" found after a rename to "Smith Law Group" is the same entity, not a new one.
- **Not yet** (Etap B): claims, snapshots and placements still reference the WordPress record ID (`id` in the API, `wp_id` in the registry). Etap B moves evidence to `entity_id` with a backfill, so terms can carry evidence too.
