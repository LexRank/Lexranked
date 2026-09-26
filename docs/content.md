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

## Page-creation rule (implemented in Phase 3)

A page exists because it contains useful, differentiated information, not
because a keyword exists (`frontend/lib/content/eligibility.ts`):

| Page | Exists when | Indexable when |
|------|-------------|----------------|
| Ranking | not thin (≥ `minEntities` scored entities, default 5) | exists and not demo |
| State / city / practice-area hub | ≥ 3 published lawyers | ≥ 3 real (non-demo) lawyers |
| Lawyer / firm profile | published | not demo |
| Listings (`/lawyers/`, `/law-firms/`) | always | contain real profiles and the API is reachable |
| Search, status | always | never |

The sitemap (`app/sitemap.ts`) lists indexable pages only. Pages that do not
exist return 404. Hub indexes show below-threshold locations as "Research in
progress" without linking to them.

## MVP scope

United States → Florida → Miami → Personal Injury, 20–50 profiles.
