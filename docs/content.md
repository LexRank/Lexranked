# Content

> Status: **design**. Implemented in Phases 6–7.

## Pipeline

```
Verified data + ranking data + methodology + sources
  → OpenAI draft → fact validation → SEO validation → quality validation
  → WordPress DRAFT
```

- Content is generated only after research and only from stored data.
- **Nothing is auto-published.** QA failure → `needs_review`; QA pass →
  `ready_for_review`; both remain WordPress drafts.

## QA checks

Factual consistency, source availability, unsupported claims, duplicate
content, keyword stuffing, unnatural language, missing context, incorrect
ranking positions, outdated information.

## Page-creation rule

A page exists because it contains useful, differentiated information — not
because a keyword exists. Ranking/location pages are generated only above a
minimum-data threshold (configurable; e.g. ≥ 5 verified, scored entities),
and thin pages are excluded from the sitemap and marked `noindex`.

## MVP scope

United States → Florida → Miami → Personal Injury, 20–50 profiles.
