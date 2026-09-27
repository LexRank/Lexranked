# LexRanked Core (WordPress plugin)

All LexRanked business logic: data model, evidence/verification, ranking
(Phase 4), research jobs, admin UI and the REST API under
`/wp-json/lexranked/v1/`. WordPress is CMS/API only; there is no public theme.

Requires WordPress ≥ 6.5 and PHP ≥ 8.2. Install/connect:
[docs/connecting-wordpress.md](../../../docs/connecting-wordpress.md).

## Structure

```
lexranked-core.php   Plugin header + bootstrap
uninstall.php        Removes settings/role only; content is kept deliberately
src/
  Plugin.php         Hook wiring, activation
  Services.php       Explicit dependency wiring
  Schema/            Field definitions, sanitizer, meta codec (pure)
  Domain/            Enums: verification, commercial status, research job lifecycle, US states
  PostTypes/         Lawyer, LawFirm, Ranking, Source, VerificationRecord, ResearchJob
  Taxonomies/        Location (state → city, USPS codes), PracticeArea
  Repository/        WordPress adapters → plain records; evidence claims; verification batches
  REST/              Controllers + DTO/ pure mappers
  Verification/      VerificationPolicy, Freshness (pure)
  Sources/           SourceTiers (configurable), ClaimValidator (pure)
  Settings/          Typed, sanitized plugin settings
  Security/          Capabilities/roles, rate limiter, API guard, headless mode, audit log
  Admin/             Menu + dashboard, settings screen, schema-driven meta boxes, list columns
  Database/          Custom tables (claims, audit log) + installer/migrations
  CLI/               wp lexranked status | seed-demo | purge-demo
  Ranking/           ScoreVersion(s), ScoreCalculator, BayesianReviewScorer, RankingEngine, InputBuilder, RankingRunner
  Research/          Research execution (Phase 5)
  Entity/            Entity registry: stable entity IDs, name/slug aliases, resolve (Etap A)
  Commercial/        Profile claims, placements (premium/featured/sponsored), status derivation (Phase 9; never read by Ranking/)
tests/Unit/          PHPUnit (no WordPress runtime)
```

## Endpoints

See [docs/api.md](../../../docs/api.md): `status`, `lawyers`, `law-firms`,
`rankings`, `states`, `cities`, `practice-areas`, `sources`,
`verifications`, `search`, `placements`, `claims` (POST, frontend server only), `entities`.

## Roles and capabilities

| Who | Can |
|-----|-----|
| Editor | Manage lawyers, firms, rankings, sources, verification records, locations, practice areas |
| Administrator | Everything above + research jobs + settings + audit log |
| LexRanked API (`lexranked_api`) | Read the API without rate limits; nothing else |

## WP-CLI

```bash
wp lexranked status
wp lexranked seed-demo [--force]   # clearly-labelled mock data (Miami · Personal Injury)
wp lexranked purge-demo --yes
wp lexranked recalculate [--ranking=<id>]   # deterministic engine run (also daily + after edits)
wp lexranked verify-snapshots              # recompute stored runs; fails if any score differs
```

## Development

```bash
composer install
composer lint   # php -l + PHPCS (WordPress Coding Standards, PHPCompatibilityWP)
composer test   # PHPUnit
../../../scripts/wp-integration-test.sh   # real WordPress in Docker
```

Conventions: WordPress Coding Standards, `declare(strict_types=1)`,
namespace `LexRanked\Core`, PSR-4 file names. Every REST route declares an
explicit `permission_callback`; every admin write checks a nonce and a
capability and is written to the audit log.
