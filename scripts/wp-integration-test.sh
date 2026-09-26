#!/usr/bin/env bash
# End-to-end test of the lexranked-core plugin inside a real WordPress
# (Docker). Spins up an isolated stack, installs WordPress, activates the
# plugin, seeds demo data and asserts on the REST API. Used by CI.
#
# Usage: scripts/wp-integration-test.sh [--keep] [--frontend]
#   --keep      leave the stack running afterwards (http://localhost:$IT_PORT)
#   --frontend  also build the Next.js frontend against this WordPress and
#               assert on the rendered public pages (end-to-end)
set -euo pipefail

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
cd "$ROOT"

export COMPOSE_PROJECT_NAME="${COMPOSE_PROJECT_NAME:-lexranked-it}"
export LOCAL_WP_PORT="${IT_PORT:-8089}"
export LOCAL_DB_PASSWORD="${LOCAL_DB_PASSWORD:-it-only-password}"
export LOCAL_DB_ROOT_PASSWORD="${LOCAL_DB_ROOT_PASSWORD:-it-only-root-password}"
KEEP=""
FRONTEND=""
for arg in "$@"; do
  case "$arg" in
    --keep) KEEP="--keep" ;;
    --frontend) FRONTEND="1" ;;
    *) echo "Unknown option: $arg" >&2; exit 2 ;;
  esac
done
FRONTEND_PORT="${FRONTEND_PORT:-3199}"
BASE="http://127.0.0.1:${LOCAL_WP_PORT}"
API="$BASE/wp-json/lexranked/v1"
COMPOSE=(docker compose --env-file /dev/null -f docker-compose.yml)

cleanup() {
  if [[ -n "${NEXT_PID:-}" && "$KEEP" != "--keep" ]]; then kill "$NEXT_PID" >/dev/null 2>&1 || true; fi
  if [[ -n "${SITE_PID:-}" ]]; then kill "$SITE_PID" >/dev/null 2>&1 || true; fi
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

echo "==> Ranking engine"
wp lexranked recalculate >/dev/null
expect "ranking comes from engine snapshots with breakdowns" '(.calculatedAt != null) and ((.entries | length) == 8) and all(.entries[]; (.breakdown | length) == 7)' "$API/rankings/best-personal-injury-lawyers-in-miami-florida-demo"
expect "second calculation reports movement" 'all(.entries[]; .movement == 0 and .isNew == false)' "$API/rankings/best-personal-injury-lawyers-in-miami-florida-demo"
expect "ranking history has both runs" '(.runs | length) == 2 and ((.runs[0].entries | length) == 8)' "$API/rankings/best-personal-injury-lawyers-in-miami-florida-demo/history"
expect "profile has breakdown summing to the score" '((.ranking.breakdown | map(.points) | add) * 100 | round) == ((.ranking.score) * 100 | round) and ((.rankings | length) == 1)' "$API/lawyers/avery-example-demo"
expect "score versions endpoint" '.active == "v1.0" and ([.versions[0].weights[].weight] | add) == 100' "$API/score-versions"
if wp lexranked verify-snapshots >/dev/null 2>&1; then pass "all snapshots reproduce exactly from stored inputs"; else fail "snapshot reproduction"; fi

echo "==> Rate limiting"
wp option update lexranked_settings '{"search_rate_per_minute":5}' --format=json >/dev/null
codes=""
for _ in $(seq 1 7); do codes+="$(curl -s -o /dev/null -w '%{http_code}' "$API/search?q=blake") "; done
if [[ "$codes" == *"429"* ]]; then pass "anonymous search is rate limited ($codes)"; else fail "no 429 in: $codes"; fi
code="$(curl -s -o /dev/null -w '%{http_code}' -u "apiuser:$APP_PW" "$API/search?q=blake")"
if [[ "$code" == "200" ]]; then pass "api user bypasses rate limit"; else fail "api user got HTTP $code"; fi

echo "==> Research engine (TypeScript worker against this WordPress)"
(
  cd workers/research
  [[ -d node_modules ]] || npm ci --no-audit --no-fund >/dev/null
  npm run build >/dev/null
)
wp user create researcher research@example.com --role=lexranked_worker >/dev/null
WORKER_PW="$(wp user application-password create researcher it --porcelain | tail -1)"
expect_status "research API is not public" 401 "$API/research/candidates"
expect_status "research API needs the research capability" 403 "$API/research/candidates" -u "apiuser:$APP_PW"
SITE_PORT="${SITE_PORT:-8098}"
node workers/research/fixtures/site/server.mjs "$SITE_PORT" &
SITE_PID=$!
DATA_DIR="$(mktemp -d)"
sed "s/SITE_HOST/127.0.0.1:${SITE_PORT}/" workers/research/fixtures/datasets/fictional-demo.csv >"$DATA_DIR/fictional-demo.csv"
for _ in $(seq 1 20); do curl -s -o /dev/null "http://127.0.0.1:${SITE_PORT}/robots.txt" && break; sleep 0.5; done
JOB="$(wp lexranked research-job candidate_discovery --params='{"dataset":"fictional-demo"}' --porcelain | tail -1)"
run_worker() {
  env LEXRANKED_API_URL="$API" LEXRANKED_WORKER_USER=researcher LEXRANKED_WORKER_APP_PASSWORD="$WORKER_PW" \
    LEXRANKED_DATA_DIR="$DATA_DIR" LEXRANKED_ALLOW_PRIVATE_NETWORK=1 LEXRANKED_PER_HOST_INTERVAL_MS=0 \
    LEXRANKED_BATCH_SIZE=2 LEXRANKED_WORKER_ID=it-worker "$@" node workers/research/dist/cli.js --once >>"$DATA_DIR/worker.log" 2>&1
}
job_json() { wp lexranked research-status "$JOB" --format=json; }
check() { if [[ "$(jq -r "$2" <<<"$3" 2>/dev/null)" == "true" ]]; then pass "$1"; else fail "$1"; echo "    got: ${3:0:400}"; fi; }

# First attempt "crashes" (hard exit) after 4 rows: the lease stays, the cursor is saved.
run_worker LEXRANKED_WORKER_CRASH_AFTER_ROWS=4 || true
check "crashed worker left the job running at its checkpoint" '.status == "running" and .cursor == "row:4"' "$(job_json)"
if grep -q "$WORKER_PW" "$DATA_DIR/worker.log"; then fail "worker logged its password"; else pass "worker logs contain no credentials"; fi
# The lease expires (simulated) and a new worker resumes from the cursor.
wp post meta update "$JOB" _lr_locked_until 2000-01-01T00:00:00Z >/dev/null
run_worker && pass "second worker run exits cleanly" || fail "second worker run failed (see $DATA_DIR/worker.log)"
status="$(job_json)"
check "job completed after resuming" '.status == "completed" and .cursor == "row:6" and .processedCount == 6 and .retryCount == 1' "$status"
check "resume is logged" '[.log[].message] | any(startswith("Resuming at row 5"))' "$status"
check "job stats recorded" '.stats.candidates_created == 5 and .stats.rows_invalid == 1 and .stats.pages_fetched == 1' "$status"
cands="$(curl -sS -u "researcher:$WORKER_PW" "$API/research/candidates?per_page=100")"
check "each candidate stored once despite the crash" 'length == 5 and ([.[].status] | unique == ["created"])' "$cands"
FIRM_ID="$(jq -r '.[] | select(.entityType == "law_firm") | .entityId' <<<"$cands")"
check "new firm is a draft" '. == "draft"' "\"$(wp post get "$FIRM_ID" --field=post_status)\""
check "website structured data applied to the draft" '. == "+1 305 555 0142"' "\"$(wp post meta get "$FIRM_ID" _lr_phone)\""
check "drafts stay out of the public API" '[.[].name] | all(. != "Sample & Fixture, P.A.")' "$(curl -sS "$API/law-firms?per_page=100")"
dupes="$(wp db query "SELECT COUNT(*) - COUNT(DISTINCT claim_hash) FROM wp_lr_claims WHERE job_id = $JOB" --skip-column-names)"
check "no duplicate claims after resume" '. == 0' "${dupes//[^0-9]/}"
check "verification requests await an editor" '. == 6' "$(wp post list --post_type=lr_verification --post_status=pending --format=count)"
kill "$SITE_PID" >/dev/null 2>&1 || true
SITE_PID=""
wp lexranked research-job verification >/dev/null
check "internal jobs run in WordPress" 'test("Processed 1 internal")' "\"$(wp lexranked research-run)\""

if [[ -n "$FRONTEND" ]]; then
  echo "==> Frontend end-to-end (Next.js against this WordPress)"
  wp option delete lexranked_settings >/dev/null 2>&1 || true
  WEB="http://127.0.0.1:${FRONTEND_PORT}"
  (
    cd frontend
    [[ -d node_modules ]] || npm ci --no-audit --no-fund >/dev/null
    # Stale fetch-cache entries from earlier local runs would be served first (stale-while-revalidate).
    rm -rf .next/cache/fetch-cache
    WORDPRESS_API_URL="$BASE/wp-json" WORDPRESS_USERNAME=apiuser WORDPRESS_APP_PASSWORD="$APP_PW" \
      NEXT_PUBLIC_SITE_URL=https://lexranked.com npm run build >/dev/null
  )
  (
    cd frontend
    WORDPRESS_API_URL="$BASE/wp-json" WORDPRESS_USERNAME=apiuser WORDPRESS_APP_PASSWORD="$APP_PW" \
      exec npx next start -p "$FRONTEND_PORT" >/dev/null 2>&1
  ) &
  NEXT_PID=$!
  for _ in $(seq 1 60); do curl -s -o /dev/null "$WEB/" && break; sleep 1; done

  # page_has <description> <path> <fixed string>
  page_has() {
    local body; body="$(curl -sS "$WEB$2" || true)"
    if grep -qF -- "$3" <<<"$body"; then pass "$1"; else fail "$1 (missing: $3)"; fi
  }
  expect_status "home" 200 "$WEB/"
  page_has "home finder lists the Miami ranking" "/" "Miami, FL"
  expect_status "ranking page (canonical location path)" 200 "$WEB/rankings/florida/miami/personal-injury/"
  page_has "ranking shows #1 entry" "/rankings/florida/miami/personal-injury/" "Avery Example (Demo)"
  page_has "ranking has ItemList JSON-LD" "/rankings/florida/miami/personal-injury/" '"@type":"ItemList"'
  page_has "demo ranking is noindex" "/rankings/florida/miami/personal-injury/" 'content="noindex, follow"'
  page_has "ranking explains methodology" "/rankings/florida/miami/personal-injury/" "Why this ranking?"
  page_has "ranking has answer-first summary from data" "/rankings/florida/miami/personal-injury/" "the top-ranked personal injury lawyers in Miami, Florida are Avery Example (Demo)"
  page_has "ranking shows editorial summary" "/rankings/florida/miami/personal-injury/" "Demo content: this sample ranking compares"
  page_has "ranking shows editorial body below the list" "/rankings/florida/miami/personal-injury/" "What to ask a personal injury lawyer"
  page_has "ranking FAQ with FAQPage JSON-LD" "/rankings/florida/miami/personal-injury/" '"@type":"FAQPage"'
  page_has "ranking shows editorial review" "/rankings/florida/miami/personal-injury/" "LexRanked Demo Editor"
  expect_status "ranking slug redirects to canonical path" 308 "$WEB/rankings/best-personal-injury-lawyers-in-miami-florida-demo/"
  expect_status "lawyer profile" 200 "$WEB/lawyers/avery-example-demo/"
  page_has "profile shows score breakdown" "/lawyers/avery-example-demo/" "Score breakdown"
  page_has "profile explains a component" "/lawyers/avery-example-demo/" "adjusted for volume to"
  page_has "profile shows sources" "/lawyers/avery-example-demo/" "Example State Bar Registry (Demo)"
  page_has "profile shows data freshness" "/lawyers/avery-example-demo/" "Data verified"
  page_has "profile canonical" "/lawyers/avery-example-demo/" '<link rel="canonical" href="https://lexranked.com/lawyers/avery-example-demo/"/>'
  page_has "profile Person JSON-LD" "/lawyers/avery-example-demo/" '"@type":"Person"'
  page_has "profile links to related lawyers" "/lawyers/avery-example-demo/" "/lawyers/blake-sample-demo/"
  expect_status "law firm profile" 200 "$WEB/law-firms/harbor-example-injury-law-demo/"
  for path in /lawyers/ /law-firms/ /rankings/ /states/ /states/florida/ /cities/ /cities/miami/ /practice-areas/ /practice-areas/personal-injury/ /methodology/ /verified/ "/search/?q=avery" /status/; do
    expect_status "page $path" 200 "$WEB$path"
  done
  expect_status "unknown ranking is 404" 404 "$WEB/rankings/texas/"
  expect_status "unknown lawyer is 404" 404 "$WEB/lawyers/does-not-exist/"
  page_has "status page reports connection" "/status/" "Connected to the LexRanked API."
  sitemap="$(curl -sS "$WEB/sitemap.xml")"
  if grep -q "/methodology/" <<<"$sitemap" && ! grep -q "demo" <<<"$sitemap"; then pass "sitemap has static pages and excludes demo pages"; else fail "sitemap content"; fi
  html="$(curl -sS "$WEB/lawyers/avery-example-demo/")"
  if grep -q "$APP_PW" <<<"$html" || grep -rqF "$APP_PW" frontend/.next/static; then fail "credentials leaked into HTML or client bundle"; else pass "no credentials in HTML or client bundle"; fi
  if [[ "$KEEP" != "--keep" ]]; then
    kill "$NEXT_PID" >/dev/null 2>&1 || true
    NEXT_PID=""
  fi
fi

echo "==> Demo purge"
wp lexranked purge-demo --yes >/dev/null
expect "purge removes all demo records" 'length == 0' "$API/lawyers"

if (( FAILURES > 0 )); then
  echo "==> $FAILURES assertion(s) failed"
  exit 1
fi
echo "==> All integration assertions passed"
