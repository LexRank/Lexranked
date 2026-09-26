# Editor guide: adding content

Everything is managed in **WordPress admin → LexRanked**. The public site
(Next.js) reads it through the API and refreshes pages about every 5 minutes.
Nothing needs a developer.

## Locations: states and cities

**LexRanked → Locations** (hierarchical, like categories).

1. **State**: *Name* `Florida`, *Parent* none, and in *State code* choose
   `FL`. The code is required for states: it is how research, rankings
   and the state page recognise the state.
2. **City**: *Name* `Miami`, *Parent* `Florida`, and no state code. The slug
   (`miami`) becomes the URL: `/cities/miami/`. For a city name that exists
   in several states, use a distinct slug such as `springfield-illinois`.

Research jobs create missing states and cities automatically, but only
from source data. You never have to pre-create them for research.

**When does a page appear?** State, city and practice-area pages exist
only when at least **3 published lawyers** are assigned there, and are
indexed by search engines when at least 3 of them are real (not demo).
Below that the URL returns 404 and the index shows "Research in
progress". This is deliberate: LexRanked does not publish thin pages
(`docs/content.md`).

## Practice areas

**LexRanked → Practice Areas**: *Name* `Personal Injury`, slug
`personal-injury` (the URL `/practice-areas/personal-injury/`). Research
and AI only ever *assign* existing practice areas; they never invent new
ones. An unknown area in a dataset is logged so you can add it.

## Lawyers and law firms

- **By hand**: **Lawyers → Add** / **Law Firms → Add**. Fill in the
  *Structured data* box, tick the location (city) and practice areas, and
  publish. Every fact should be backed by evidence (next section).
- **By research** (recommended for volume): prepare a seed CSV from
  public sources (the format is in `docs/research.md`) and run a
  `candidate_discovery` job. New people and firms arrive as **drafts**.
  Review each one, then publish it.

Publishing triggers recalculation of scores and rankings within about a minute.

## Evidence, sources and verification

- **Sources**: the pages facts come from, with a source type that sets the
  tier. Research creates them as *Pending*: check each one and publish it.
- **Verification records**: licence, bar status, identity and so on. Research
  creates them as *Pending* and caps "verified" by source tier. Publish
  after checking.
- **LexRanked → Research review**: candidates the matcher could not decide
  (with an optional AI second opinion), and new evidence about published
  profiles. Approve or reject each item. Nothing there is public until you
  approve it.

## Rankings

**Rankings → Add**:

1. Title, e.g. *Best Personal Injury Lawyers in Miami, Florida*.
2. *Ranks*: lawyers or law firms. *Minimum entities* (default 5): below it
   the ranking is "thin", with no entries shown and noindex.
3. Tick **one location** (a city, or a state for a statewide ranking) and
   **one practice area**. These set the URL:
   `/rankings/florida/miami/personal-injury/`.
4. Write the SEO/GEO text: *Summary* (above the ranking), the editor body
   (guide below the ranking) and the *FAQ* (`Question | Answer` per line).
   Set *Reviewed by* / *Reviewed on*.
5. Publish. The engine calculates positions. You never set positions by hand,
   and payment never changes them.

To draft the text with AI, run a `content_generation` job; see below.

## AI content drafts

**LexRanked → AI Content Drafts** (when AI assistance is enabled in
Settings). Each draft shows a QA report (✅ ready / ⚠️ needs review) and the
exact numbered facts the text was written from. Edit if needed, save,
then press **Apply to ranking**. See `docs/ai.md`.

## Blog / editorial articles

Editorial articles will be normal **WordPress Posts** (Posts → Add New):
title, text, featured image, category. **The public site does not show
them yet**: the `/articles/` section (article pages, list, author and
date, `Article` JSON-LD, sitemap) is built in **Phase 7**, together with
AI-assisted article drafts. Posts you write now will appear there once
Phase 7 ships.

## Research jobs

**LexRanked → Research Jobs → Add** (or WP-CLI, see `docs/research.md`):
pick the job type, put parameters as JSON (for example
`{"dataset":"florida-personal-injury"}`), optionally tick a location and
practice area as the scope, and publish. The job box shows progress, retries
and the log. Workers pick jobs up automatically.

## Demo data

`wp lexranked seed-demo` creates clearly labelled sample data (always
noindex); `wp lexranked purge-demo` removes it. Remove it before launch.
