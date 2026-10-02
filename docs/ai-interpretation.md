# AI Interpretation Layer (Etap J)

LexRanked is not a site that writes about lawyers. It collects, normalises,
verifies and orders facts, and only then derives rankings, comparisons and
text. The data flows one way:

```
DATA → EVIDENCE → RANKING → PAGE → AI SUMMARY
```

It never flows the other way: an AI article is never mined for facts. The
model sits at the end of this chain and only **interprets** what the backend
has already established.

## What the model may and may not do

| May | Must not |
|---|---|
| **summarize** facts in plain language, answer first | invent a fact, name, date, award, review, fee or outcome |
| **explain** what the facts show (e.g. why an entry has its position, from the engine's own "why" explanation) | research or recall anything from memory |
| **compare** differences the facts show | compute a number: no averages, totals, differences, percentages or rankings of its own |
| **classify** into supplied labels, quoting evidence (research extraction, Phase 6) | decide, change, predict or judge a position or score |
| **write** editorial text built on the facts | say who is better or who to hire, promise results, add links or calls to action |

Every text also adds something a generic page on the topic would not have: at least one specific, useful point from the facts (a verified credential, a check date, a market figure with its sample size). It must be true and cited; with nothing distinctive in the facts, the model adds nothing.

These rules are one contract, `workers/research/src/ai/interpretation.ts`
(`interp/2`). Every prompt starts with the task and ends with the same rules.
Each draft records its prompt version, e.g. `interp/2+ranking-content/2`, so
it is always known which contract produced it.

## What the model is given

Only numbered facts, built deterministically from the public API.

**Rankings**
- Positions and scores come from the ranking snapshot.
- "Why #N" comes from `RankingExplainer` (Etap D).
- The "best for" context and its counts come from Etap F.
- The number of verified entries comes from the page eligibility decision (Etap G).
- Sources come from the fact layer (Etap H).
- Market figures come from `GET /market` (Etap I).

**Hubs**
- Market-wide figures come from `GET /market`, not from the handful of profiles shown. The older partial counts ("verified among the profiles shown") are used only when the API has no market endpoint.

**Profiles**
- Each statement carries its evidence status from `aiSummary`: verified, sourced or computed.

Each fact has a **status**:
- `verified`: LexRanked verified it;
- `sourced`: a cited source states it, but it is not verified yet;
- `computed`: LexRanked calculated it.

Each fact also has an **origin**, e.g. `market mkt-1.0`, `ranking snapshot`, `page eligibility`, `ranking explainer`, `fact layer` or a source name. The model sees the status; the draft stores both.

## How it is checked

Deterministic QA (`src/content/qa.ts`) runs on every draft. An optional AI
review may only add issues. The checks that matter for Etap J:

| Code | Meaning |
|---|---|
| `missing_fact_refs`, `unknown_fact_ref` | every statement cites supplied facts |
| `unsupported_number` | every number in the text appears in a cited fact, so a number the model computed itself fails |
| `wrong_position` | a position next to a name matches the ranking |
| `overstated_verification` | "verified" rests on a verified fact, not on a sourced one |
| `ranking_decision` | no "should be ranked higher", "better lawyer", "better than", "we recommend", "you should hire" |
| `forbidden_claim`, `promotional_language`, `link_in_text` | no promises, superlatives or links |

Any error marks the draft `needs_review`.

## What happens to the output

Nothing is published automatically. Output is stored as a WordPress content
draft together with:
- its facts, including status and origin;
- the QA report;
- the model;
- the prompt version.

An editor reviews and applies it. The ranking engine never reads AI text.
Positions, scores, market figures and eligibility come only from the backend.
