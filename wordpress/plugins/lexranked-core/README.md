# LexRanked Core (WordPress plugin)

All LexRanked business logic: data model, evidence/verification, ranking
engine, research jobs, admin UI and the REST API under
`/wp-json/lexranked/v1/`. WordPress is used as CMS/API only; there is no
public theme.

Requires WordPress ≥ 6.5 and PHP ≥ 8.2.

## Structure

```
lexranked-core.php   Plugin header + bootstrap
src/
  Autoloader.php     PSR-4 autoloader (no vendor/ needed in production)
  Plugin.php         Hook wiring, activation checks, REST namespace constant
  REST/              REST controllers (Phase 1: StatusController)
  PostTypes/         Custom post types (Phase 2)
  Taxonomies/        Locations, practice areas (Phase 2)
  Ranking/           ScoreCalculator, RankingEngine, ScoreVersion, snapshots (Phase 4)
  Sources/           Source registry and tiers (Phase 2/5)
  Verification/      Verification framework (Phase 2/5)
  Research/          Research jobs (Phase 5)
  Admin/             Admin dashboard (Phase 2)
  Security/          Capabilities, rate limiting, audit log (Phase 2/8)
tests/Unit/          PHPUnit unit tests (no WordPress runtime)
```

## Endpoints

| Method | Path | Auth |
|--------|------|------|
| GET | `/wp-json/lexranked/v1/status` | public (no private data) |

## Development

```bash
composer install
composer lint   # php -l + PHPCS (WordPress Coding Standards, PHPCompatibilityWP)
composer test   # PHPUnit
```

Conventions: WordPress Coding Standards, `declare(strict_types=1)`,
namespace `LexRanked\Core`, PSR-4 file names (the WPCS file-name sniff is
disabled for this reason). Every REST route must declare an explicit
`permission_callback`.
