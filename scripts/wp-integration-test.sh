#!/usr/bin/env bash
# End-to-end test of the lexranked-core plugin inside a real WordPress
# (Docker). Spins up an isolated stack, installs WordPress, activates the
# plugin, seeds demo data and asserts on the REST API. Used by CI.
#
# Usage: scripts/wp-integration-test.sh [--keep]
#   --keep  leave the stack running afterwards (http://localhost:$IT_PORT)
set -euo pipefail

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
cd "$ROOT"

export COMPOSE_PROJECT_NAME="${COMPOSE_PROJECT_NAME:-lexranked-it}"
export LOCAL_WP_PORT="${IT_PORT:-8089}"
export LOCAL_DB_PASSWORD="${LOCAL_DB_PASSWORD:-it-only-password}"
export LOCAL_DB_ROOT_PASSWORD="${LOCAL_DB_ROOT_PASSWORD:-it-only-root-password}"
KEEP="${1:-}"
BASE="http://127.0.0.1:${LOCAL_WP_PORT}"
API="$BASE/wp-json/lexranked/v1"
COMPOSE=(docker compose --env-file /dev/null -f docker-compose.yml)

cleanup() {
  if [[ "$KEEP" != "--keep" ]]; then
    "${COMPOSE[@]}" down -v --remove-orphans >/dev/null 2>&1 || true
  fi
}
trap cleanup EXIT

# Filter Compose's container lifecycle chatter; real errors still reach stderr.
wp() { "${COMPOSE[@]}" run --rm -T --quiet-pull wpcli wp "$@" 2> >(grep -vE '^\s*Container ' >&2); }

FAILURES=0
pass() { echo "  ✓ $1"; }
fail() { echo "  ✗ $1"; FAILURES=$((FAILURES + 1)); }
# expect <description> <jq filter that must output "true"> <url> [curl args...]
expect() {
  local desc="$1" filter="$2" url="$3"; shift 3
  local body
  body="$(curl -sS "$@" "$url" || true)"
  if [[ "$(jq -r "$filter" <<<"$body" 2>/dev/null)" == "true" ]]; then pass "$desc"; else fail "$desc"; echo "    body: ${body:0:300}"; fi
}
expect_status() {
  local desc="$1" want="$2" url="$3"; shift 3
  local got
  got="$(curl -sS -o /dev/null -w '%{http_code}' "$@" "$url" || true)"
  if [[ "$got" == "$want" ]]; then pass "$desc"; else fail "$desc (HTTP $got, expected $want)"; fi
}

echo "==> Building the plugin ZIP and testing the packaged artefact"
ZIP="$(scripts/build-plugin-zip.sh)"
PKG_DIR="$ROOT/dist/it"
rm -rf "$PKG_DIR" && mkdir -p "$PKG_DIR"
unzip -q "$ZIP" -d "$PKG_DIR"
export LEXRANKED_PLUGIN_DIR="$PKG_DIR/lexranked-core"

echo "==> Starting WordPress ($COMPOSE_PROJECT_NAME on port $LOCAL_WP_PORT)"
"${COMPOSE[@]}" up -d --quiet-pull db wordpress >/dev/null 2>&1
for _ in $(seq 1 60); do
  if curl -s -o /dev/null "$BASE/wp-login.php"; then break; fi
  sleep 2
done

echo "==> Installing WordPress and activating the plugin"
wp core install --url="http://localhost:${LOCAL_WP_PORT}" --title="LexRanked IT" --admin_user=admin \
  --admin_password="it-admin-password" --admin_email=admin@example.com --skip-email >/dev/null
wp rewrite structure '/%postname%/' >/dev/null
wp plugin activate lexranked-core >/dev/null
wp lexranked seed-demo >/dev/null
wp user create apiuser api@example.com --role=lexranked_api >/dev/null
APP_PW="$(wp user application-password create apiuser it --porcelain | tail -1)"

echo "==> API assertions"
expect "status endpoint" '.status == "ok" and .namespace == "lexranked/v1"' "$API/status"
expect "lawyers list sorted by score" '(length == 8) and (.[0].ranking.score >= .[1].ranking.score) and all(.[]; .isDemo)' "$API/lawyers?per_page=100"
expect "list DTO has no private fields" 'all(.[]; has("private") | not) and (tostring | contains("@") | not)' "$API/lawyers?per_page=100"
expect "filter by state code + practice area" 'length == 8' "$API/lawyers?state=FL&practice_area=personal-injury&per_page=100"
expect "unknown state yields empty list" 'length == 0' "$API/lawyers?state=texas"
expect "lawyer detail with evidence and freshness" '.verification.status == "verified" and (.sources | length) > 0 and .freshness.isStale == false and .firm != null' "$API/lawyers/avery-example-demo"
expect "detail by numeric id" '.slug == "avery-example-demo"' "$API/lawyers/$(curl -s "$API/lawyers/avery-example-demo" | jq .id)"
expect "failed verification surfaces" '.verification.status == "failed"' "$API/lawyers/harper-exemplar-demo"
expect "law firms with lawyer counts" 'length == 3 and all(.[]; .lawyerCount > 0)' "$API/law-firms"
expect "ranking ordered, commercial separate" '(.entries | length) == 8 and ([.entries[].position] == [1,2,3,4,5,6,7,8]) and (.entries[0].entity.commercial.status == "free")' "$API/rankings/best-personal-injury-lawyers-in-miami-florida-demo"
expect "demo ranking is never indexable" 'all(.[]; .indexable == false)' "$API/rankings"
expect "states" '.[0].code == "FL" and .[0].lawyerCount == 8' "$API/states"
expect "cities" '.[0].slug == "miami" and .[0].state.code == "FL"' "$API/cities?state=florida"
expect "practice areas" '.[0].slug == "personal-injury"' "$API/practice-areas"
expect "sources with tiers" 'length == 3 and all(.[]; .tier != null)' "$API/sources"
expect "verifications hide notes and reviewer" '(length > 0) and (tostring | contains("demo-seeder") | not)' "$API/verifications?per_page=100"
expect "search" '.[0].slug == "avery-example-demo"' "$API/search?q=avery"
expect_status "unknown parameter rejected" 400 "$API/lawyers?nope=1"
expect_status "per_page capped" 400 "$API/lawyers?per_page=1000"
expect_status "edit context needs auth" 401 "$API/lawyers/avery-example-demo?context=edit"
expect_status "api role cannot read private context" 403 "$API/lawyers/avery-example-demo?context=edit" -u "apiuser:$APP_PW"
expect_status "404 for unknown lawyer" 404 "$API/lawyers/does-not-exist"
expect_status "core users endpoint hidden from anonymous" 404 "$BASE/wp-json/wp/v2/users"
expect_status "CPTs not exposed via wp/v2" 404 "$BASE/wp-json/wp/v2/lr_lawyer"

echo "==> Rate limiting"
wp option update lexranked_settings '{"search_rate_per_minute":5}' --format=json >/dev/null
codes=""
for _ in $(seq 1 7); do codes+="$(curl -s -o /dev/null -w '%{http_code}' "$API/search?q=blake") "; done
if [[ "$codes" == *"429"* ]]; then pass "anonymous search is rate limited ($codes)"; else fail "no 429 in: $codes"; fi
code="$(curl -s -o /dev/null -w '%{http_code}' -u "apiuser:$APP_PW" "$API/search?q=blake")"
if [[ "$code" == "200" ]]; then pass "api user bypasses rate limit"; else fail "api user got HTTP $code"; fi

echo "==> Demo purge"
wp lexranked purge-demo --yes >/dev/null
expect "purge removes all demo records" 'length == 0' "$API/lawyers"

if (( FAILURES > 0 )); then
  echo "==> $FAILURES assertion(s) failed"
  exit 1
fi
echo "==> All integration assertions passed"
