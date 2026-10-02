# Commercial features (Phase 9)

Claimed profiles, identity checks, premium profiles, featured profiles and
sponsored listings (spec §54). The one rule everything below serves:

> **Payment never changes a score, a position, a verification status or which
> profiles are ranked — and every paid element is labelled.**

## How the separation is enforced

| Layer | Guarantee |
|---|---|
| Storage | Claims (`lr_profile_claims`) and placements (`lr_placements`) live in their own tables. The only thing written to a profile is the display-only `commercial_status` meta, which is read-only in the editor and derived automatically. |
| Engine | `src/Ranking` has no reference to commercial data. `CommercialTest::testRankingCodeNeverReadsCommercialData` fails the build if a commercial identifier appears in its code; `EntityInput` has no commercial field (`RankingEngineTest`). |
| API | Ranking entries carry no placement data. Placements come from `GET /placements`, one page at a time, with `isPaidPlacement: true` and a disclosure. |
| Frontend | Organic cards and ranking rows render **byte-identically** for paying and non-paying profiles (unit test). Placements appear in their own dashed "Sponsored" / "Featured profiles" block *after* the organic list, without position or score, labelled "· Paid", with a link to `/advertising/`. They are never in the `ItemList` JSON-LD. Premium call-to-action links carry `rel="sponsored"`. |
| End-to-end | The integration test records positions and scores with placements live, cancels every placement and revokes every claim, recalculates, and requires identical results. |

## Profile claims

```
visitor ── form on /claim/<lawyer|law-firm>/<slug>/ ──▶ Next.js server action
        (honeypot, per-client rate limit, validation)  ──▶ POST /claims (API role)
WordPress: status pending_email, token hash stored, email with /claim/confirm/?token=…
visitor ── opens link, presses "Confirm" (GET never acts; mail scanners open links) ──▶ POST /claims/confirm
WordPress: pending_review, email to the site admin
editor ── LexRanked → Profile claims: signals + identity check ──▶ Approve (method required) / Reject
WordPress: status "claimed" on the profile, email to the claimant, audit log
```

- **Identity is checked by a person.** The review screen shows signals —
  bar number matches the profile (✅/❌), email domain vs the firm website,
  free-mail providers, "already claimed" — but approval requires the editor
  to record *how* identity was checked: state bar record, phone call-back to a
  listed number, firm email, or a document.
- One approved claim per profile; a second one must wait until the first is
  revoked. Revoking (**Reject** on an approved claim) removes the "Claimed"
  badge and makes the profile's placements ineligible at once.
- Claiming gives the owner a channel for corrections. Corrections still
  need a public source and go through normal evidence review; a claim does not
  make anything "verified".
- Abuse limits: 3 claims per email and 10 per profile per day (CMS), 5 form
  posts per minute per client (frontend), a honeypot field, and
  indistinguishable responses for repeats.
- **Privacy:** claimant details are visible only to claim reviewers and are
  never in the public API or logs (the audit log stores IDs only). Links
  expire after 48 hours; rejected and expired claims have their personal data
  erased after 30 days (hourly job). Approved claims keep contact details
  while the claim stands.

## Paid products

| Product | Where | Shown as |
|---|---|---|
| Premium profile | the profile page | a card "Premium profile · Paid" with the owner's message (≤ 600 chars, plain text, no links) and a contact button (`https` only, `rel="sponsored"`), below the organic facts and score |
| Featured profile | one state, city **or** practice-area page | a "Featured profiles" block after the editorial lists (max `max_featured_per_page`, default 3) |
| Sponsored listing | one ranking page | a "Sponsored" block after the ranked list (max `max_sponsored_per_ranking`, default 2) |

Eligibility (checked when a placement is saved **and** every time a page is
served): the profile is published, has an approved claim, its bar status is
not inactive / suspended / disbarred / retired, and — for featured and
sponsored — it belongs to the page's location and practice area (a city
profile belongs on its state's pages). Placements run from their start date
to their end date (exclusive, UTC, at most a year), can be paused or
cancelled, and are shown oldest booking first. There is no bidding and no
ordering by price or score.

Payment itself is handled outside WordPress (invoice, payment provider); an
administrator records the placement with a private order reference. No card
data or payment credentials ever touch LexRanked.

## Managing it

- **LexRanked → Profile claims** (editors, administrators): review queue with
  counts per status; open a claim to see the signals and approve / reject.
- **LexRanked → Placements** (administrators only): list, add, edit, pause,
  cancel.
- **Settings:** *Profile claims* on/off, maximum sponsored listings per
  ranking (0–5), maximum featured profiles per page (0–6).
- WP-CLI:

```bash
wp lexranked claims --status=pending_review
wp lexranked claim-review 12 --approve --identity=bar_record --note="Checked on the Florida Bar site"
wp lexranked claim-review 12 --reject --note="Could not verify"
wp lexranked placement-add --product=sponsored --entity=101 --ranking=42 --starts=2026-10-01 --ends=2026-11-01 --order=INV-1001
wp lexranked placement-add --product=featured --entity=77 --entity-type=law_firm --location=15
wp lexranked placement-add --product=premium --entity=101 --message="…" --cta=https://firm.example/contact
wp lexranked placements
wp lexranked placement-cancel 7
wp lexranked commercial-sync   # what the hourly job does
```

Every change is in the audit log (`claim.*`, `placement.*`) and refreshes the
frontend immediately (signed revalidation). Placements that start or end are
picked up by the hourly job.

## Email

Claim emails use `wp_mail()`. In production configure a transactional mail
service (SMTP plugin or the host's mail relay) with SPF/DKIM for the sending
domain; otherwise confirmation emails may not arrive. Set **Frontend URL** in
Settings so links point at the public site.

## Monitoring

The `claims` health check warns when a confirmed claim has waited more than
7 days for review.

## Contact details on profiles

The website link is shown on every profile. The phone number and email are
shown as plain text until the profile is **active** (claimed by its owner
with confirmed identity, or premium); only then are they click-to-call
(`tel:`) and click-to-email (`mailto:`) links
(`frontend/components/profile/Contact.tsx`). This never affects a score or
a position.
