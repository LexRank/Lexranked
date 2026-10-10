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
exact numbered facts the text was written from. Edit if needed and save,
then press the button:

| Draft type | Button | What it does |
|---|---|---|
| Ranking content | Apply to ranking | summary, guide and FAQ of the ranking |
| Hub content | Apply to page | summary, guide and FAQ of the state / city / practice area |
| Profile summary | Apply to profile | the profile summary |
| Article | Create article draft | a new **draft** Post; review and publish it like any post |

Jobs: `content_generation` with `{"kind":"ranking"}` (default), `{"kind":"hub"}`,
`{"kind":"profile"}` or `{"kind":"article","topic":"…","ranking":42}`.
See `docs/ai.md`.

## Blog / editorial articles (guides)

Articles are normal **WordPress Posts** (**Posts → Add New**) and appear at
`/articles/<slug>/`, in the **Guides** menu and on the home page.

- Write the title and text in the editor, set a featured image and alt
  text, and a category if you use them. The *Excerpt* is the summary shown on
  cards and in search results.
- In the **Structured data** box: optionally pick the *Related ranking*,
  which is linked from the article, and fill *Reviewed by* / *Reviewed on*.
- The author shown is the WordPress user's *Display name*.
- Articles under **300 words** are published but kept out of search
  engines, like any thin page. Delete WordPress's default "Hello world!" post.
- Articles are marked up as `Article` for search engines (author, dates,
  reviewer, image) and are listed in the sitemap.

## Text on state, city and practice-area pages

The same text can be written through the editorial API (`/editorial/*`,
see `docs/api.md`) by any user with editor rights, for example the
LexRanked assistant's editor account. Changes are audit-logged.

Open the location or practice area (**LexRanked → Locations / Practice
Areas → Edit**). The fields below the standard ones are:
*Page summary* (above the list), *Guide* (below the list, basic HTML),
*FAQ* (`Question | Answer` per line), *Reviewed by* and *Reviewed on*. The text
shows only when the page exists, which needs at least 3 published lawyers.

## Profile summaries

Lawyers and law firms have a *Profile summary* field: 2-4 plain sentences
shown at the top of the profile and used as its search description.

## Profile claims (lawyers and firms claiming their profile)

Every profile has a **Claim this profile** link. After the claimant confirms
their email, the claim appears in **LexRanked → Profile claims** (with a
counter in the menu).

1. Open the claim. Check the signals: does the bar number match the profile?
   Is the email on the firm's website domain?
2. **Verify the identity yourself**: look the lawyer up on the state bar
   website, call the number listed there or on the firm website, or check a
   document.
3. Choose how you checked it and press **Approve claim**, or **Reject claim**
   with a private note. The claimant is emailed either way.

An approved profile shows "Claimed by the lawyer / firm". It does not change
the score or position and does not make anything "verified". Corrections the
owner sends you still need a public source. To undo an approval, open the
claim and press **Revoke claim**. Details: `docs/commercial.md`.

## Paid placements (administrators)

**LexRanked → Placements**, after payment has been arranged outside
WordPress. Pick the product (premium / featured / sponsored), the profile ID,
the page (ranking ID for sponsored; one location **or** practice-area term
ID for featured), the dates and an order reference. Only claimed profiles in
good bar standing that belong to that page can be placed; the form tells you
why otherwise. Every placement is labelled "Paid" on the site and never
changes a ranking. Pause or cancel from the same screen.

## Autonomous research

**LexRanked → Settings → Autonomous research** (off by default) lets the
research worker create its own research jobs and publish results without
waiting for you, but only what passes every fixed check:

- a **lawyer** is published when they have a name, city, practice area, bar
  number and an *active* bar status, their licence and bar status are
  verified by an official source (the state bar), and no sources disagree;
- a **law firm** needs a website and a verified business record;
- their verification records and sources are published with them;
- a **ranking** for a city and practice area is created once at least
  *Minimum entities for a ranking* profiles there are published.

Everything else stays a **draft**, and the job log says why (for example
"Kept #63 as a draft: bar status is not active"). Review those drafts as
usual. To undo an automatic publication, switch the profile back to draft
or move it to the trash; the audit log records every automatic publication.
AI content drafts and profile claims are never published automatically.

## Research jobs

**LexRanked → Research Jobs → Add** (or WP-CLI, see `docs/research.md`):
pick the job type, put parameters as JSON (for example
`{"dataset":"florida-personal-injury"}`), optionally tick a location and
practice area as the scope, and publish. The job box shows progress, retries
and the log. Workers pick jobs up automatically.

## Demo data

`wp lexranked seed-demo` creates clearly labelled sample data (always
noindex), including a demo claim and demo paid placements; `wp lexranked purge-demo` removes it. Remove it before launch.
