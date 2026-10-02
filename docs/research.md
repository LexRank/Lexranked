# Research system

> Status: **implemented in Phase 5** (jobs, candidates, sources, claims,
> verification, retries, logging, TypeScript worker); AI assistance added in
> Phase 6 through the same validated intake — see [ai.md](ai.md).

## Principles

1. **WordPress is the source of truth.** Workers only *propose*: every
   submission is validated, deduplicated and applied by fixed rules inside
   the `lexranked-core` plugin.
2. **Nothing is published without passing fixed rules.** New lawyers and
   firms are created as **drafts**; evidence about published profiles waits
   in an editorial review queue; research-created sources and verification
   records are `pending` posts. With **Autonomous research** on (off by
   default, see below), a completed job publishes only what passes every
   check of `AutoPublishPolicy`; anything doubtful stays a draft with the
   reason.
3. **Every fact has a source.** A claim without a source URL/record and
   a retrieval date is rejected. Candidates must name the source they came
   from.
4. **No invention, no scraping of directories, no LLM decisions.** Discovery
   starts from human-curated seed datasets; the worker reads only
   schema.org structured data a site publishes about itself; matching and
   verification are deterministic rules.
5. **Resumable and idempotent.** Jobs checkpoint a cursor after every batch;
   every write is idempotent (candidate dedupe keys, claim hashes, source
   URLs), so a crashed batch can be replayed safely.

## Pipeline

```
Seed dataset (curated CSV) ──► Candidates ──► Deterministic matching ──► match | draft | review
                                    │                                        │
                                    ▼                                        ▼
                              Source records ◄── Claims (evidence) ◄── Website JSON-LD (optional)
                                                     │
                                                     ▼
                                   Fact resolution (tier → status → recency)
                                   drafts: applied · published: review queue
                                                     │
                     Verification requests ──► VerificationRules (tier caps) ──► pending records
                                                     │
                                                     ▼
                                          Ranking recalculation (debounced)
```

## Components

| Where | Component | Responsibility |
|---|---|---|
| Plugin `Research/JobService` | Job queue | claim with lease (MySQL `GET_LOCK`), heartbeat, complete, fail + backoff, resume after lease expiry, internal jobs via WP-cron |
| Plugin `Research/JobPolicy` | Pure rules | what is claimable, retry schedule, progress sanitizing |
| Plugin `Research/CandidateInput` · `CandidateNormalizer` | Pure | candidate validation, name normalization, dedupe key |
| Plugin `Research/CandidateMatcher` | Pure | match / create / review decision with a human-readable reason |
| Plugin `Research/EntityIndex` | Index | normalized name, name key and website domain in post meta for fast matching |
| Plugin `Research/FactResolver` | Pure | best value per field: source tier, then status, then recency, confidence, ID; conflicts flagged |
| Plugin `Research/VerificationRules` | Pure | caps `verified` by source tier per verification type |
| Plugin `Research/ResearchIngest` | Intake | sources, candidates, claims, verifications, draft creation, applying facts |
| Plugin `Research/ResearchLog` | Log | per-job log (redacted context) |
| Plugin `Research/AutoPublishPolicy` · `AutoPublisher` | Autonomous research | pure publish / keep-as-draft rules; applies them when a job completes |
| Plugin `REST/ResearchController` | API | private `/research/*` endpoints (see [api.md](api.md#research-api-private)) |
| Plugin `Admin/ResearchAdmin` | Admin | job progress/log box, **LexRanked → Research review** page |
| `workers/research` | TypeScript worker | claims jobs, runs pipelines, heartbeats, reports outcome |

## Job types

| Type | Runs in | What it does | Params |
|---|---|---|---|
| `candidate_discovery` | worker | seed dataset → candidates → claims (+ website JSON-LD) → verification requests | `dataset` (required), `fetch_websites` (default `true`) |
| `source_refresh` | worker | re-reads the websites of entities in the job's scope (Location / Practice Area terms on the job) | `entity_type` (`lawyer` \| `law_firm`, optional) |
| `verification` | WordPress (cron) | marks published verification records past `expires_at` as `expired` | — |
| `ranking_recalculation` | WordPress (cron) | scores all entities and recalculates published rankings | — |
| `ai_candidate_review` | worker + AI | advisory AI verdict on candidates in review ([ai.md](ai.md)) | — |
| `content_generation` | worker + AI | drafts with QA for rankings, hubs, profiles and articles ([ai.md](ai.md)) | `kind` (ranking\|hub\|profile\|article), `rankings`, `hubs`, `entities`, `topic`, `ranking`, `ai_qa` |

`source_refresh` and `candidate_discovery` also accept `ai_extraction: true`
(quote-checked AI extraction for pages without structured data). AI job
types wait until **AI assistance** is enabled in Settings.

Create jobs in **LexRanked → Research Jobs** (set parameters as JSON and the
scope with the taxonomy boxes), through the API when **Autonomous research**
is on (`POST /research/jobs`, `candidate_discovery` and `source_refresh`
only), or with WP-CLI:

```bash
wp lexranked research-job candidate_discovery --params='{"dataset":"florida-personal-injury"}' --location=miami
wp lexranked research-status 42          # progress, stats, log
wp lexranked research-run                # run due internal jobs now
wp lexranked research-reindex            # rebuild the matching index
wp lexranked research-auto-publish 42    # apply the autonomous-research rules to a completed job
```

## Autonomous research

**LexRanked → Settings → Autonomous research** (off by default). When it is
on, research workers may create `candidate_discovery` and `source_refresh`
jobs through the API, and every completed job is passed to `AutoPublisher`
(a job can opt out with `"auto_publish": false`). For each lawyer or firm
the job **created** (matched, existing profiles are never touched),
`AutoPublishPolicy` decides:

| Entity | Published only when all of these hold |
|---|---|
| Lawyer | still a draft, not demo, no conflicting facts (sources disagree → doubt), a name, a city under a state, at least one practice area, bar state + bar number, bar status `active`, and **license** and **bar status** checks `verified` (only a tier-1 source can verify them, see Verification) with no failed or expired record for the same check |
| Law firm | the same general checks, a website, and a `verified` **business** check |

- Published: the profile, its verification records and the sources behind
  them (tier ≤ 2) are published; scores and rankings recalculate as usual.
- Kept: the profile stays a draft; the reasons are stored in
  `_lr_auto_publish_hold` and logged on the job ("Kept #… as a draft: …").
  Its records and sources stay pending for an editor.
- Rankings: for each city and practice area of a newly published profile,
  a ranking ("Best Personal Injury Lawyers in Miami, Florida") is created and
  published when there are at least *Minimum entities for a ranking*
  published, non-demo profiles and no ranking for that pair exists in any
  status. The engine calculates positions; editorial text can be added later.
- Evidence about profiles that are already published (a later job adding,
  say, a board certification as an award) is approved and applied when it
  comes from an official (tier 1) source, was not AI-extracted, targets a
  profile field and the field is empty or already has that value; anything
  that would change a shown value stays in the review queue.
- Seed datasets may carry `years_experience`, `languages` (`a; b`),
  `education` and `awards` (`Name | Issuer | Year; …`), read from the same
  source as the row.
- Unchanged: AI content drafts are never published automatically, claims
  stay pending, payment never affects anything, and every publication is in
  the audit log (`research.auto_published`, `research.ranking_created`).

Re-applying the rules (`wp lexranked research-auto-publish <job>`) is
idempotent: published items are skipped and held drafts are re-checked.

## Job lifecycle, leases and retries

```
pending ──claim──► running ──complete──► completed
   ▲                 │  │
   │                 │  └─fail(retryable)──► failed ──(backoff elapsed)──claim──► running
   │                 │                          └─(no retries left / permanent)── final
   │                 └─lease expired (worker died)──claim──► running (resume from cursor)
   └── admin: failed → pending (manual retry)        admin: any → cancelled
```

- **Lease.** `claim` returns a random token; every later call must send it
  in `X-LexRanked-Lease`. The lease lasts *Research job lease* minutes
  (default 10) and is extended by each heartbeat. A worker that lost the
  lease (expired, taken over, or cancelled by an admin) gets `409` and must
  stop — it can never write into a job someone else owns.
- **Retry with backoff.** `fail` with `retryable: true` schedules the next
  attempt at `base × 2^(attempt−1)` seconds (default base 300 s, capped at
  6 h) up to *Research job retries* (default 3). `retryable: false`
  (invalid parameters, missing dataset) fails immediately.
- **Crash recovery.** A running job whose lease expired is claimable again;
  the new worker resumes from the stored `cursor`. This consumes one retry;
  after the last one the job fails with an explicit message.
- **Cursor.** Opaque to WordPress; the discovery pipeline uses `row:<n>`
  (rows fully processed), refresh uses `id:<last entity ID>`.
- Settings: **LexRanked → Settings** (retries, backoff base, lease minutes).

## Candidates and matching

A candidate is a *lead* — "this name appears in that source" — never a fact.
It is deduplicated by `sha1(type | normalized name | city | state)` and
matched against existing lawyers/firms of **any** status:

| Situation | Decision |
|---|---|
| Firm with the same website domain in the same state (one firm) | **match** (0.95) |
| Same normalized name in the same state and the same city or domain | **match** (0.9–1.0) |
| Same name, same state, different/unknown city | **review** |
| Several profiles with that name, or same name in another state | **review** |
| Lawyer: same last name + first initial in the same city | **review** |
| No candidate | **create** a draft |

A lawyer's website domain alone never merges two people (colleagues share
the firm domain). Normalization strips honorifics and suffixes (Esq., J.D.,
Hon.), middle initials, diacritics and firm-form noise (LLP, P.A., "Law
Group", "&"). Candidates in **review** appear on **Research review** with the
suggested profile; editors pick *Same as #…*, *Create draft* or *Reject*.

## Evidence (claims) and fact resolution

- Claims are validated by `ClaimValidator` (traceable field, configured
  source type, http(s) URL or source record, retrieval date, confidence) and
  identified by `claim_hash = sha1(entity, field, value, source)`. Re-reading
  the same fact from the same source only refreshes `retrieved_at`.
- Research claims are always stored with `verification_status = pending`;
  only verification records certify facts.
- `review_status`: `approved` (public evidence), `pending_review` (research
  evidence about a published profile — hidden from the public API and the
  ranking engine until an editor approves it) or `rejected`.
- For **draft / pending** entities the `FactResolver` picks the best value
  per field and writes it — but never over a value an editor typed into a
  draft that research did not create, and never when the best sources
  disagree (the conflict is logged and the profile flagged for review).
  Locations are created under their state when missing; practice areas are
  only assigned from existing terms (unknown ones are logged).

## Verification

`VerificationRules` caps what a source can prove: identity, license, bar
status and business need a **tier 1** source; location, website and
practice area tier ≤ 2; review data tier ≤ 4. A `verified` request from a
weaker source is recorded as `pending` (and logged as downgraded). Records
get `verified_at`, `expires_at` from the freshness rules, `verified_by =
research:job-<id>`, and are created as **pending** posts an editor publishes
(or that autonomous research publishes with a profile that passes every check).

## Worker (`workers/research`)

TypeScript, Node ≥ 22, no runtime dependencies (global `fetch`,
`node:dns`). Dev dependencies: TypeScript, Vitest, `@types/node`.

```bash
cd workers/research
npm ci && npm run build
LEXRANKED_API_URL=https://cms.example.com/wp-json/lexranked/v1 \
LEXRANKED_WORKER_USER=research-worker \
LEXRANKED_WORKER_APP_PASSWORD='xxxx xxxx xxxx xxxx xxxx xxxx' \
LEXRANKED_DATA_DIR=/srv/lexranked/datasets \
node dist/cli.js --loop        # or --once (cron / CI)
```

| Variable | Default | Meaning |
|---|---|---|
| `LEXRANKED_API_URL` | — | REST base (`…/wp-json/lexranked/v1`); https required except localhost |
| `LEXRANKED_WORKER_USER` / `LEXRANKED_WORKER_APP_PASSWORD` | — | user with the **LexRanked Research Worker** role + application password |
| `LEXRANKED_DATA_DIR` | `data` | directory of seed datasets (`<dataset>.csv`) |
| `LEXRANKED_WORKER_ID` | `research-<pid>` | shown on the job |
| `LEXRANKED_WORKER_JOB_TYPES` | both | `candidate_discovery,source_refresh` |
| `LEXRANKED_POLL_SECONDS` | 30 | idle poll interval (`--loop`) |
| `LEXRANKED_HEARTBEAT_SECONDS` | 60 | lease keep-alive while fetching (keep below the lease) |
| `LEXRANKED_BATCH_SIZE` | 10 | rows per checkpoint |
| `LEXRANKED_FETCH_TIMEOUT_MS` / `_MAX_BYTES` | 10000 / 2 MB | per page |
| `LEXRANKED_PER_HOST_INTERVAL_MS` | 2000 | politeness delay per host |
| `LEXRANKED_USER_AGENT` | `LexRankedBot/0.5 (+…)` | robots.txt matches the product token |
| `LEXRANKED_ALLOW_PRIVATE_NETWORK` | off | **tests only**: allow fetching private addresses |

Create the worker account once:

```bash
wp user create research-worker research@example.com --role=lexranked_worker
wp user application-password create research-worker worker --porcelain
```

The role can call the research API and read the public API — nothing in
wp-admin.

### Seed datasets

CSV, one row per lawyer/firm **as read by a person from a public source**:

| Column | Required | Notes |
|---|---|---|
| `entity_type` | ✓ | `lawyer` or `law_firm` |
| `name` | ✓ | as written in the source |
| `source_url` | ✓ | the page the person read (e.g. bar directory profile) |
| `source_type` | ✓ | a configured source type (`bar_association`, `official_registry`, …) |
| `retrieved_at` | ✓ | date the source was read |
| `city`, `state`, `practice_area`, `website`, `phone` | | only what the source states |
| `bar_state`, `bar_number`, `bar_status` | | lawyers; from the bar source |
| `confidence` | | 0–1, default 0.9 |

Invalid rows are skipped and logged; they keep their position so the
cursor stays stable. Real datasets live outside the repository; the fixtures
in `workers/research/fixtures/` are fictional test data.

### Fetching rules

http(s) only · hosts resolving to private, loopback, link-local or
metadata addresses are refused (SSRF guard; each redirect hop re-checked,
max 3) · robots.txt honoured per RFC 9309 (unreachable → disallowed) · one
request per host per interval · timeout and body cap · HTML only. Facts are
taken only from JSON-LD nodes whose `@type` fits the entity and whose name
normalizes to the entity's name: `telephone`, `address` (locality, region,
postal code, street for firms), same-domain `url`, business `email` for
firms. Known limitation: DNS is resolved separately for the check and the
request (rebinding window); run workers on a network without access to
internal services.

## Logging

- Worker: JSON lines on stdout (`{"ts","level","msg",…}`); keys that look
  like secrets are redacted recursively.
- Job log: warnings, errors and summaries are sent with heartbeats and stored
  in `lr_research_log` (redacted); visible on the job screen, via
  `wp lexranked research-status <id>` and `GET /research/jobs/{id}/log`.
- Job statistics (`candidates_created`, `claims_stored`, `claims_duplicate`,
  `pages_fetched`, `verifications_downgraded`, …) are stored on the job.

## AI usage (Phase 6)

See [ai.md](ai.md): quote-checked extraction and practice-area
classification, advisory match review, ranking content drafts with QA — all
with strict JSON Schemas, validated output and no publication.
