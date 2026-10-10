# AI assistance (Phase 6)

OpenAI is an **intelligence layer, never the source of truth**. It runs in the
worker (`workers/research`), so the API key never reaches WordPress, the
browser or the public site. Every model output is structured (JSON Schema,
strict mode), validated again in code, and checked before it can influence
anything. Nothing the model produces is published or decides a ranking position.

| Use | Where | What the model may do | Guard | Lands as |
|---|---|---|---|---|
| **Extraction** | `ai/extract.ts` (source refresh / discovery, `ai_extraction: true`) | propose field values from one fetched page, with a verbatim quote each | the quote must be in the page, the value must be in the quote (phone numbers compared by digits), and the page must name the entity | `method: "ai"` claims, confidence ≤ 0.6 → drafts / review queue only |
| **Classification** | same call | pick practice areas from the **existing** taxonomy (enum), with quotes | the quote must be in the page; unknown areas are impossible | `practice_areas` claim |
| **Entity matching** | `ai/matchReview.ts` (`ai_candidate_review` job) | second opinion on candidates the rule-based matcher sent to review | advisory only | a note beside the candidate on **Research review** |
| **Content drafting** | `content/generate.ts` (`content_generation` job) | ranking, hub (state/city/practice area) and profile text, and editorial articles, from a numbered fact list, citing fact IDs | schema enum of fact IDs + deterministic QA + optional AI QA | an **AI Content Draft** (WordPress draft) with a QA report |
| **QA** | `content/qa.ts` (+ `content/aiQa.ts`) | the AI reviewer may add issues | issues must quote text that exists in the draft; it can never clear a deterministic error | issues in the draft's QA report |

## Switching it on

1. **WordPress**: LexRanked → Settings → *AI assistance* (off by default).
   While it is off, AI jobs stay `pending`, and `method: "ai"` claims, AI notes
   and content drafts are rejected.
2. **Worker environment** (server-side only):

| Variable | Default | Meaning |
|---|---|---|
| `OPENAI_API_KEY` | - | API key. Enables AI |
| `OPENAI_MODEL` | picked at startup | Optional override. Without it the worker lists the models the key can use and takes the first of `TEXT_MODEL_PREFERENCE` in `src/ai/models.ts`: the current cost-efficient tier (good quality, low price; not the flagship), then older fallbacks. The choice is logged at startup. Update the list when a new generation ships |
| `OPENAI_BASE_URL` | `https://api.openai.com/v1` | https only (localhost allowed for tests) |
| `OPENAI_MAX_CALLS_PER_JOB` | 200 | Cost cap per job attempt |
| `OPENAI_TIMEOUT_MS` | 60000 | Per request |

3. Create jobs: **Research Jobs → Add** or WP-CLI:

```bash
wp lexranked research-job content_generation                          # all published, non-thin rankings
wp lexranked research-job content_generation --params='{"rankings":[42]}'
wp lexranked research-job content_generation --params='{"kind":"hub","hubs":"city"}'
wp lexranked research-job content_generation --params='{"kind":"profile","entities":[101,102]}'
wp lexranked research-job content_generation --params='{"kind":"article","topic":"What to ask a personal injury lawyer in Miami","ranking":42}'
wp lexranked research-job ai_candidate_review                         # candidates waiting for review
wp lexranked research-job source_refresh --params='{"ai_extraction":true}' --location=miami
```

Requests are sent with `store: false`, strict `json_schema` output and a
per-job call budget. 429 and 5xx responses are retried with backoff. Refusals,
incomplete output, invalid JSON and schema violations are rejected and
counted in the job stats (`ai_rejected_outputs`). They are never repaired or guessed.

## Prompts and versions

Prompt code carries a version (`extract-profile/1`, `match-review/1`,
`ranking-content/1`) that is stored on every note and draft. A change to a
prompt bumps its version, so every output can be traced to the instructions
that produced it. Fetched pages go only into the user message, and the system
prompt tells the model to treat them as untrusted data. Injected instructions
could at most produce values that fail the quote check.

## Content drafts

`content_generation` builds a deterministic, numbered fact list for each
published, non-thin ranking (`content/facts.ts`), from public API data only:
published entities, approved evidence and the latest engine run. The
model must cite fact IDs for the summary, every paragraph and every FAQ
answer (enforced by the schema's `enum`).

Deterministic QA (`content/qa.ts`):

| Check | Severity |
|---|---|
| Paragraph / answer without fact references, or unknown references | error |
| A number not present in the cited facts (`unsupported_number`) | error |
| A ranking position that does not match the named entity (`wrong_position`) | error |
| Promises and promotion: "guarantee", "will win", "100%", "no win no fee", "call now", "sponsored" | error |
| Links in the text | error |
| "the best", "top-rated", "award-winning", "leading" … (`promotional_language`) | warning |
| Keyword stuffing (target phrase > 6 times or > 3 per 100 words) | warning |
| Repeated sentences; > 60 % overlap with the page's current text | warning |
| Fewer than 120 words | warning |
| Ranking data older than 30 days; demo data | warning |

Any error → `needs_review`; otherwise `ready_for_review`. WordPress
re-validates the payload (plain text only, bounded lengths, facts required),
builds the body HTML itself from escaped paragraphs and recomputes the QA
status. A worker cannot mark a draft with errors as ready.

**Applying a draft.** Open *LexRanked → AI Content Drafts*, read the QA
report and the facts, edit the text if needed and save, then press
**Apply to ranking**. This copies the summary, body and FAQ into the ranking;
the previous body stays in the ranking's revisions. A `needs_review` draft
requires ticking "I have checked every problem". Every apply is recorded in the
audit log.

## Content kinds (Phase 7)

| `kind` | Targets | Facts | Skipped when | Applied to |
|---|---|---|---|---|
| `ranking` (default) | published rankings (`rankings` IDs to narrow) | positions, scores, verification, movement, methodology | thin (< 3 entries) | ranking summary, body, FAQ |
| `hub` | states, cities, practice areas (`hubs`: all/state/city/practice_area) | counts, highest-scoring profiles, practice areas, rankings | < 3 published lawyers (the page does not exist) | term summary, guide, FAQ |
| `profile` | published lawyers and firms (`entities` IDs to narrow) | published fields, score, verification, positions | < 5 facts beyond name and type | profile `summary` |
| `article` | one article per job (`topic` brief, optional `ranking` for context) | ranking facts (if given) + methodology facts | - | a new **draft** Post |

Articles may contain *uncited general guidance* ("ask who will handle your
case"), which is the only exception to citing facts. QA forbids numbers
in uncited text, and flags names of lawyers or firms that are not cited.

## Evidence transparency

Evidence items in the public API carry `method` (`manual`, `seed`,
`structured_data`, `ai`). AI-extracted evidence about a published profile
is stored with `review_status = pending_review`: it is invisible to the
public and to the engine until an editor approves it. `FactResolver` treats it as low
confidence, so it never overrides structured data from the same source tier.

## Testing without OpenAI

Unit tests use a scripted client (`test/fakeAi.ts`) that validates like
the real one. The end-to-end test (`scripts/wp-integration-test.sh`) runs
the worker against `workers/research/fixtures/openai/server.mjs`, a **fake**
local Responses endpoint that returns canned, schema-valid output. CI never
calls OpenAI and needs no key.

## Featured images for articles

`lexranked-images` (worker package) generates an illustration for an article
and sets it as the featured image:

```bash
cd workers/research && npm run build
OPENAI_API_KEY=… \
LEXRANKED_API_URL=https://cms.example.com/wp-json/lexranked/v1 \
LEXRANKED_WORKER_USER=editor-account LEXRANKED_WORKER_APP_PASSWORD='…' \
node dist/images-cli.js --post 42 [--post 43] [--force]
```

- The prompt is built from the article's title and excerpt plus fixed rules:
  an editorial illustration with **no people, faces, hands, text, logos or
  seals** (a lawyer-ranking site must not suggest real people or
  endorsements).
- The image is uploaded to the media library with alt text
  ("Illustration for the article “…”") and set as the featured image.
  Posts that already have one are skipped unless `--force`.
- The image model is picked the same way from `IMAGE_MODEL_PREFERENCE`
  (override: `OPENAI_IMAGE_MODEL`).
- The WordPress user needs `upload_files` and `edit_posts` (an editor).

