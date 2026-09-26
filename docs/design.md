# Design system

Direction: **modern American legal authority**. Calm, trustworthy and
data-forward, like a quality financial or legal publication. It does not
look like a directory full of ads.

## Tokens (`frontend/app/globals.css`)

| Token | Value | Use |
|-------|-------|-----|
| `--navy-900` / `--navy-950` | `#0b1f3a` / `#061426` | Headers, hero, footer, score rings |
| `--brass-500` / `--brass-300` | `#b08d57` / `#d9c29a` | Accents, primary CTA, score progress |
| `--paper` | `#faf8f4` | Page background (warm off-white) |
| `--surface` | `#ffffff` | Cards |
| `--verified` / `--pending` / `--failed` | green / amber / red | Verification badges only |

Typography: **Source Serif 4** for headings (editorial, legal gravitas) and
**Inter** for UI and body text. Both are self-hosted via `next/font`, so
visitors' browsers make no third-party font requests.

American cues are deliberately restrained: the navy and brass palette, a
subtle stripe texture in the hero, and ★ separators in the trust bar. There
are no flags or clip-art.

## Components (`frontend/components/`)

- `ScoreRing`: LexRank score as a brass progress ring on navy. A null score
  renders "Not scored" and is never estimated.
- `StarRating`: rendered only when a rating exists, with an accessible
  "out of 5" label.
- `VerificationBadge`: verified / pending / failed / expired / not verified.
- `CommercialBadge`: "Featured · Paid placement" or "Sponsored · Paid
  placement". It is always visible when present.
- `DemoBadge` / `DemoNotice`: an unmistakable label on mock data.
- `Monogram`: initials avatar. The site uses no photos until verified images
  exist.
- `RankingEntry`, `LawyerCard`, `FirmCard`, `RankingCard`, `HubPage`,
  `ListingPage`, `TermIndex`, `PageHeader`, `Breadcrumbs`, `MethodologyPanel`,
  `RankingFinder` (the only client component).

## Principles

- Mobile first. Everything works at 390 px and has no horizontal page scroll.
  Tables scroll inside their own container.
- Accessible:
  - semantic landmarks, a skip link and visible focus rings;
  - ARIA labels for scores and ratings;
  - `prefers-reduced-motion` respected;
  - the mobile menu works without JavaScript (`<details>`).
- Unknown data is omitted, never shown as placeholders.
- Paid placements are always labelled and never styled as organic rank.
