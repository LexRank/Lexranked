# Content

> Status: **implemented** — editorial fields on rankings, hubs and profiles, articles at `/articles/`, and AI drafts with QA for all of them ([ai.md](ai.md)). Editors: see [editor-guide.md](editor-guide.md).

## Pipeline

```
Verified data + ranking data + methodology + sources
  → OpenAI draft → fact validation → SEO validation → quality validation
  → WordPress DRAFT
```

- Content is generated only after research and only from stored data.
- This applies to AI content drafts. Research profiles follow their own
  rules: with **Autonomous research** on, profiles that pass every check are
  published automatically ([research.md](research.md#autonomous-research)).
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
| Article (`/articles/[slug]`) | published, no password | not demo and ≥ 300 words |
| Guides index (`/articles/`) | always | lists at least one indexable article |
| Listings (`/lawyers/`, `/law-firms/`) | always | contain real profiles and the API is reachable |
| Search, status | always | never |

The sitemap (`app/sitemap.ts`) lists indexable pages only. Pages that do not
exist return 404. Hub indexes show below-threshold locations as "Research in
progress" without linking to them.

## MVP scope

United States → Florida → Miami → Personal Injury, 20–50 profiles.

## Ranking page layout (SEO + GEO)

The ranking stays near the top of the page. The editorial text goes above and
below it:

| Position | Block | Source |
|----------|-------|--------|
| Above | **Answer-first summary**: top 3, count, methodology, verified count, average rating | Generated deterministically from ranking data (`lib/content/rankingFacts.ts`); it cannot state unsupported facts |
| Above | Editorial summary, 1–3 sentences | Ranking field `summary` (plain text) |
| Above | "At a glance" facts: ranked, verified, average rating, reviews | Data |
| — | **The ranking** | Engine / API |
| Below | Guide (H2 sections) | Ranking post content (WordPress editor) |
| Below | FAQ (`FAQPage` JSON-LD): editorial questions about the practice area and place first, then questions the ranking's data answers, with one short "How were these ranked?" linking to `/methodology/` | Ranking field `faq` (`Question \| Answer` per line) + `lib/content/relatedQuestions.ts` |
| Below | About this ranking: updated date, methodology, editorial review, independence | Data + `reviewed_by` / `reviewed_at` |

The methodology is explained on `/methodology/`; content pages only link to
it (sidebar "How we rank", one FAQ question) instead of repeating it.

GEO signals:
- a quotable factual summary with named entities, dates and numbers;
- `dateModified`, `lastReviewed` and `reviewedBy` in `WebPage` JSON-LD;
- an ordered `ItemList`;
- the FAQ;
- consistent entity URLs.

Editorial text must follow the content rules above. AI drafts (Phase 6/7)
land as WordPress drafts and are never auto-published.

## Guides (blog) template

`/articles/<slug>/` (`frontend/app/articles/[slug]/page.tsx`):
breadcrumbs Home › Guides › Category › Guide; a table of contents built from
the guide's own `<h2>` headings (ids added automatically); a "Find a … lawyer"
band with the rankings for the guide's practice area (car-accident and other
injury categories lead to personal injury rankings); related guides (same
category first); and a sticky sidebar with the table of contents, matching
rankings, categories with counts and more guides.

Categories are WordPress categories (one per guide, from the content plan);
`/articles/category/<slug>/` lists a category and is indexed (and in the
sitemap) once it holds at least three indexable guides. The content plan for
the first 100 guides is in [content-plan.md](content-plan.md).

