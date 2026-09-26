# Ranking methodology — LexRank

> Status: **implemented (Phase 4)**. Engine: `wordpress/plugins/lexranked-core/src/Ranking/`.

## Guarantees

- **Deterministic and reproducible.** `ScoreCalculator` is a pure function
  of (inputs, context, version). There is no clock, randomness, database or
  LLM inside it. Every calculation stores its exact inputs in a snapshot, and
  `wp lexranked verify-snapshots` recomputes stored runs and fails if any
  score differs.
- **No LLM decides a position.** Order: score DESC → number of sourced facts
  DESC → entity ID ASC.
- **Payment never changes the organic score.** `EntityInput` has no
  commercial field. A unit test asserts that no payment-related property
  exists and that extra stored keys are ignored.
- **Explainable.** Each component stores its points, its maximum, a
  plain-English explanation and the inputs that were missing. The profile
  breakdown is built only from these stored values.
- **Unknown is never guessed.** A missing input scores 0 for its component
  and is listed as missing.

## Versions

A version (`ScoreVersion`) is an immutable, named set of weights (which must
sum to 100) and parameters:
- Built-in versions live in code (`ScoreVersions::builtin()`), so any change
  is reviewed and versioned in git.
- A built-in id can never be redefined.
- Changing weights means adding a new version, e.g. `v1.1`.
- The active version is selected in **LexRanked › Settings**.
- A ranking may pin its own version.

## v1.0

| Component | Weight | Factor (0–1) |
|-----------|--------|--------------|
| Reputation | 30 | 0.5 × min(awards, 5)/5 + 0.5 × ln(1 + reviews)/ln(1 + 500) |
| Review strength | 20 | Bayesian rating (below), mapped linearly from 3.0 → 0 to 5.0 → 1 |
| Experience | 15 | min(years, 25)/25 |
| Practice-area relevance | 15 | 0 if the ranked practice area is not listed; otherwise 0.5 + 0.5 / (number of practice areas) |
| Professional credentials | 10 | Lawyers: active bar status 0.5 + verified license 0.25 + education on record 0.25. Firms: verified business 0.5 + verified website 0.25 + ≥ 1 profiled lawyer 0.25 |
| Local relevance | 5 | Based in the ranked city (or ranked state for state rankings) 1; elsewhere in the same state 0.5; otherwise 0 |
| Data quality | 5 | 0.4 × key facts present/7 + 0.3 × key facts backed by evidence/7 + 0.3 × (verified 1, pending 0.5, else 0) |

Key facts: rating, review count, years of experience, bar status, website,
practice areas and location.

Points = factor × weight, rounded to 2 decimals. The total is the sum of the
points, so the breakdown always adds up to the score exactly.

### Review strength (isolated module)

`BayesianReviewScorer` implements the `ReviewScorer` interface and can be
replaced without touching the rest of the engine:

```
adjusted = (C × m + n × r) / (C + n)        v1.0: m = 4.0, C = 25
```

Example: 5.0 stars from 3 reviews → 4.11 adjusted, while 4.8 from 400
reviews → 4.75. No review text is copied and no quotations are invented.

## Context

Scores depend on context:
- **Rankings:** the ranking's practice area and location.
- **Entity-level scores** (shown on profiles and used for list sorting): the
  entity's own primary practice area and city.

Law firms use the longest-practising profiled lawyer for experience.

## Runs and snapshots

`RankingRunner` recalculates:
- daily (WP-cron `lexranked_recalculate`);
- about 60 seconds after edits to lawyers, firms, rankings or verification
  records;
- on demand, from the **Recalculate now** button or with
  `wp lexranked recalculate`.

Each run writes rows to `{prefix}lr_ranking_snapshots` (`run_id`,
`ranking_id` — 0 for entity-level scores — `entity_id`, `position`, `score`,
`score_version`, `context`, `components`, `inputs`, `calculated_at`). Runs
are inserted in a transaction. Public rankings are served from the latest
run, with **movement** measured against the previous run. The history is
available at `GET /rankings/{id}/history`.
