# Ranking methodology — LexRank

> Status: **design** (Phase 1). Implemented in Phase 4.

## Guarantees

- **Deterministic and reproducible.** A score is a pure function of stored
  data and a versioned weight configuration. Recomputing an old snapshot with
  the same score version and inputs yields the same number.
- **No LLM decides a position.**
- **Payment never changes the organic score.** Commercial status is not an
  input to the calculator.
- **Explainable.** Only component scores that can be explained from stored
  data are shown.

## Components (score version v1.0 defaults)

| Component | Weight |
|-----------|--------|
| Reputation | 30 |
| Review strength | 20 |
| Experience | 15 |
| Practice-area relevance | 15 |
| Professional credentials | 10 |
| Local relevance | 5 |
| Data quality | 5 |
| **Total** | **100** |

Weights are configuration tied to a `score_version`; changing them creates a
new version rather than silently altering existing scores.

## Review strength

Star averages alone are misleading (5.0 from 3 reviews vs 4.8 from 400). The
review module uses a **Bayesian average** that shrinks a rating toward a
prior mean in proportion to how few reviews support it:

```
bayesian = (C × m + n × r) / (C + n)
```

`r` = rating, `n` = review count, `m` = prior mean, `C` = prior weight
(confidence constant). The module is isolated behind an interface so it can
be replaced without touching the rest of the engine. No review text is
copied and no quotations are fabricated.

## Missing data

A missing input scores **zero for that component**, never an imputed guess,
and lowers the data-quality component. This rewards complete, verified
profiles rather than inventing information.

## Ranking snapshots

Each calculation writes snapshots (`ranking_id, entity_id, position, score,
score_version, calculated_at`) enabling ranking history and change
explanations. Ties break deterministically (score → verified data count →
entity ID).
