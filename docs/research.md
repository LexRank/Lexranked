# Research system

> Status: **design**. Implemented in Phase 5 (pipeline) and Phase 6 (AI).

## Pipeline

```
Topic → Candidate discovery → Source collection → Source normalization
      → Entity matching → Fact extraction → Verification → Database update
      → Ranking recalculation
```

- Source collection is never skipped; every extracted fact becomes an
  evidence claim pointing at a stored source.
- AI-generated text is never a source of truth.

## Jobs

Statuses: `pending → running → completed | failed | cancelled`.

Resumability: jobs persist a **cursor** and `processed_count` after each
batch. A failed job restarted after 200 records resumes from record 201.
Per-record failures are recorded and do not fail the whole job.

## Error handling

Every external call: timeout, bounded retries with exponential backoff and
jitter, structured log, explicit failure state.

## Logging

Structured (JSON lines), e.g.

```json
{"ts":"2026-09-23T19:14:23Z","job":"research_8391","status":"completed","location":"miami-fl","practice":"personal-injury","candidates":127,"verified":82,"failed":4,"duration_s":960}
```

Secrets are never logged; request bodies are redacted.

## AI usage (Phase 6)

Classification, entity matching, extraction, duplicate detection — always
with strict JSON Schemas, validated output, malformed output rejected. The
model may only extract values present in a supplied source document.
