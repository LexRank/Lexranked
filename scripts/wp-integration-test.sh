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
# Shared by WordPress and Next.js for signed on-demand revalidation (test-only value).
export LEXRANKED_REVALIDATE_SECRET="${LEXRANKED_REVALIDATE_SECRET:-it-only-revalidate-secret-$(date +%s)-0123456789abcdef}"
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
  if [[ -n "${AI_PID:-}" ]]; then kill "$AI_PID" >/dev/null 2>&1 || true; fi
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

# check <description> <jq filter that must output "true"> <json>
check() { if [[ "$(jq -r "$2" <<<"$3" 2>/dev/null)" == "true" ]]; then pass "$1"; else fail "$1"; echo "    got: ${3:0:400}"; fi; }

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
# Test-only mail sink (captures claim emails; see scripts/it/mail-sink.php).
"${COMPOSE[@]}" exec -T wordpress sh -c 'mkdir -p wp-content/mu-plugins && cat > wp-content/mu-plugins/lexranked-it-mail-sink.php' <scripts/it/mail-sink.php
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
expect "profile has breakdown summing to the score" '((.ranking.breakdown | map(.points) | add) * 100 | round) == ((.ranking.score) * 100 | round) and ((.rankings | length) == 2)' "$API/lawyers/avery-example-demo"
expect "score versions endpoint: v1.1 reads the fact layer, v1.0 kept" '.active == "v1.1" and ([.versions[0].weights[].weight] | add) == 100 and ([.versions[] | select(.id == "v1.1")][0].input == "facts") and ([.versions[] | select(.id == "v1.0")][0].input == "profile")' "$API/score-versions"
if wp lexranked verify-snapshots >/dev/null 2>&1; then pass "all snapshots reproduce exactly from stored inputs"; else fail "snapshot reproduction"; fi

echo "==> Entity layer (Etap A)"
expect "every lawyer and firm has a stable entity ID" '(map(.entityId) | all(. != null)) and (map(.entityId) | unique | length) == length' "$API/lawyers?per_page=100"
expect "locations and practice areas are entities too" '(.[0].entityId != null)' "$API/states"
expect "cities carry entity IDs" 'all(.[]; .entityId != null)' "$API/cities"
expect "practice areas carry entity IDs" 'all(.[]; .entityId != null)' "$API/practice-areas"
DREW_EID="$(curl -sS "$API/lawyers/drew-specimen-demo" | jq .entityId)"
DREW_ID="$(curl -sS "$API/lawyers/drew-specimen-demo" | jq .id)"
expect "entity endpoint" ".entityId == $DREW_EID and .entityType == \"lawyer\" and .canonicalName == \"Drew Specimen (Demo)\" and .path == \"/lawyers/drew-specimen-demo/\"" "$API/entities/$DREW_EID"
ENTITIES_BEFORE="$(wp db query "SELECT COUNT(*) FROM wp_lr_entities" --skip-column-names | tr -dc 0-9)"
wp post update "$DREW_ID" --post_title="Drew Specimen-Renamed (Demo)" --post_name=drew-specimen-renamed-demo >/dev/null
expect "a rename keeps the entity ID" ".entityId == $DREW_EID and .name == \"Drew Specimen-Renamed (Demo)\"" "$API/lawyers/drew-specimen-renamed-demo"
check "a rename creates no new entity" ". == $ENTITIES_BEFORE" "$(wp db query "SELECT COUNT(*) FROM wp_lr_entities" --skip-column-names | tr -dc 0-9)"
expect "the former slug resolves to the renamed entity" ".entityId == $DREW_EID and .path == \"/lawyers/drew-specimen-renamed-demo/\"" "$API/entities/resolve?type=lawyer&slug=drew-specimen-demo"
check "former and current names are both stored" '. == 2' "$(wp db query "SELECT COUNT(*) FROM wp_lr_entity_aliases WHERE entity_id = $DREW_EID AND alias_type = 'name'" --skip-column-names | tr -dc 0-9)"
expect_status "unknown slugs do not resolve" 404 "$API/entities/resolve?type=lawyer&slug=nobody-at-all"
expect_status "resolve validates the type" 400 "$API/entities/resolve?type=ranking&slug=x"

echo "==> Attributes, claims, facts, sources (Etap B)"
expect "data dictionary separates facts from derived metrics" '(.attributes | map(select(.key == "bar_status"))[0].layer == "fact") and (.attributes | map(select(.key == "review_strength"))[0].layer == "derived") and (.attributes | map(select(.key == "years_experience"))[0].unit == "years")' "$API/attributes"
check "every claim is keyed by its entity" '. == 0' "$(wp db query "SELECT COUNT(*) FROM wp_lr_claims WHERE lr_entity_id = 0" --skip-column-names | tr -dc 0-9)"
check "raw and normalised values are stored side by side" '. > 0' "$(wp db query "SELECT COUNT(*) FROM wp_lr_claims WHERE field_name = 'rating' AND value_normalized IS NOT NULL" --skip-column-names | tr -dc 0-9)"
expect "profile facts: one per attribute, with source, tier and freshness" '(.facts | map(.attribute) | index("bar_status") != null) and ((.facts[] | select(.attribute == "bar_status")) | .status == "verified" and .value == "active" and .source.tier == 1 and .source.tierLabel == "Official / regulatory" and .freshness.category == "bar_status" and .verifiedAt != null) and ((.facts[] | select(.attribute == "review_count")) | .value == 387 and .freshness.category == "review_data")' "$API/lawyers/avery-example-demo"
expect "evidence keeps the raw value and the normalised one" '[.sources[] | select(.field == "website")][0] | (.normalizedValue | type) == "string"' "$API/lawyers/avery-example-demo"
expect "sources are objects: domain, tier label, status" 'all(.[]; .domain != null and .tierLabel != null and .status != null)' "$API/sources"
check "provenance traces fact → claim → source → ranking input" '.[0].attribute == "bar_status" and (.[0].claim | test("raw=")) and (.[0].source | test("tier 1")) and (.[0].ranking_input != "—")' "$(wp lexranked provenance lawyer:avery-example-demo --attribute=bar_status --format=json)"

echo "==> Data Quality Score (Etap C)"
expect "the Data Quality model is published (weights sum to 100)" '.version == "dq-1.0" and ([.dimensions[].weight] | add) == 100 and .summary.count >= 11' "$API/data-quality"
expect "profiles carry a Data Quality score with five dimensions" '.dataQuality.score > 0 and .dataQuality.score <= 100 and (.dataQuality.dimensions | length) == 5 and ([.dataQuality.dimensions[] | select(.key == "completeness")][0].score == 100) and .dataQuality.missing == [] and .dataQuality.version == "dq-1.0"' "$API/lawyers/avery-example-demo"
expect "a lawyer with failed verification scores lower on verification" '[.dataQuality.dimensions[] | select(.key == "verification")][0].score < 100' "$API/lawyers/harper-exemplar-demo"
check "CLI explains the score" 'test("Data Quality [0-9.]+% \\(dq-1.0\\)")' "$(wp lexranked quality lawyer:avery-example-demo | sed -n 1p | jq -Rs .)"
expect "Data Quality is not part of the ranking entries" '(.entries | tostring | test("dataQuality|quality_score") | not)' "$API/rankings/best-personal-injury-lawyers-in-miami-florida-demo"

echo "==> Ranking explanations and methodology v1.1 (Etap D)"
RANKING_URL="$API/rankings/best-personal-injury-lawyers-in-miami-florida-demo"
expect "entries were calculated with v1.1 from the fact layer" 'all(.entries[]; .scoreVersion == "v1.1")' "$RANKING_URL"
expect "every entry explains its position from its components" 'all(.entries[]; (.why.summary | startswith("Ranks #")) and (.why.strengths | type) == "array") and .entries[0].why.behind == null and .entries[1].why.behind.position == 1' "$RANKING_URL"
BLAKE_WP="$(curl -sS "$API/lawyers/blake-sample-demo" | jq .id)"
wp eval "\\LexRanked\\Core\\Plugin::services()->claims->insert( array( 'entity_id' => $BLAKE_WP, 'entity_type' => 'lawyer', 'field_name' => 'review_count', 'value' => 900, 'source_url' => 'https://example.com/demo/reviews', 'source_type' => 'review_platform', 'retrieved_at' => gmdate( 'c' ), 'confidence' => 0.8, 'verification_status' => 'verified' ) );" >/dev/null
expect "new evidence reaches the fact layer" '(.facts[] | select(.attribute == "review_count")) | .value == 900 and .status == "verified"' "$API/lawyers/blake-sample-demo"
wp lexranked recalculate >/dev/null
expect "the change is explained from the snapshot difference" '(.entries[] | select(.entity.slug == "blake-sample-demo")) | .change != null and ([.change.reasons[].text] | any(test("review count 154 → 900"))) and ([.change.reasons[].type] | index("component") != null)' "$RANKING_URL"
if wp lexranked verify-snapshots >/dev/null 2>&1; then pass "v1.0 and v1.1 snapshots all reproduce from their stored inputs"; else fail "snapshot reproduction after v1.1"; fi

echo "==> Comparison engine (Etap E)"
AVERY_E="$(curl -sS "$API/lawyers/avery-example-demo" | jq .entityId)"
BLAKE_E="$(curl -sS "$API/lawyers/blake-sample-demo" | jq .entityId)"
EMERY_E="$(curl -sS "$API/lawyers/emery-mockwell-demo" | jq .entityId)"
FIRM_E="$(curl -sS "$API/law-firms/harbor-example-injury-law-demo" | jq .entityId)"
COMPARE="$API/compare?type=lawyer&entities=$AVERY_E,$BLAKE_E"
expect "compares two lawyers by stable entity ID, in request order" '.version == "cmp-1.0" and ([.entities[].name] == ["Avery Example (Demo)", "Blake Sample (Demo)"])' "$COMPARE"
expect "every cell carries status, source and check date or says not on record" 'all(.rows[].cells[]; (.status == "missing" and .value == null) or (.status != "missing" and .checkedAt != null or .status == "directory"))' "$COMPARE"
expect "numeric rows mark the higher stored value" '(.rows[] | select(.key == "review_count")) as $r | ($r.highest | length) == 1 and ($r.cells[] | select(.id == $r.highest[0]) | .value) == 900' "$COMPARE"
expect "the summary states differences from data, never a verdict" '(.summary | any(test("Blake Sample \\(Demo\\) has more reviews on record \\(900 vs 387\\)"))) and (.summary | any(test("Both practice Personal Injury"))) and (.summary | all(test("better|recommend|should hire"; "i") | not))' "$COMPARE"
expect "shared ranking positions come from the same snapshot" '.sharedRankings[0].path == "/rankings/florida/miami/personal-injury/" and ([.sharedRankings[0].positions[].position] | length) == 2' "$COMPARE"
expect "commercial status is never a comparison dimension" '(tostring | test("premium|commercial|placement|sponsored"; "i") | not)' "$API/compare?type=lawyer&entities=$AVERY_E,$EMERY_E"
expect "firms compare on firm rows" '([.rows[].key] | index("lawyers") != null and index("years_experience") == null)' "$API/compare?type=law_firm&entities=$FIRM_E,$(curl -sS "$API/law-firms/bayside-sample-legal-group-demo" | jq .entityId)"
expect_status "one entity is not a comparison" 400 "$API/compare?type=lawyer&entities=$AVERY_E"
expect_status "the same entity twice is not a comparison" 400 "$API/compare?type=lawyer&entities=$AVERY_E,$AVERY_E"
expect_status "types are not mixed" 404 "$API/compare?type=law_firm&entities=$AVERY_E,$FIRM_E"
expect_status "unknown entity" 404 "$API/compare?type=lawyer&entities=$AVERY_E,999999"
expect "profiles offer the neighbours above and below as comparisons" '.rankings[0].neighbors | length == 2 and all(.[]; .entityId > 0)' "$API/lawyers/blake-sample-demo"

echo "==> Contextual rankings (Etap F)"
CAR="$API/rankings/best-car-accident-lawyers-in-miami-florida-demo"
SPANISH="$API/rankings/best-spanish-speaking-personal-injury-lawyers-in-miami-florida-demo"
expect "a case-type ranking has a context path under its practice area" '.path == "/rankings/florida/miami/personal-injury/car-accidents/" and .context.type == "case_type" and .context.label == "Car Accidents" and .context.parent.path == "/rankings/florida/miami/personal-injury/"' "$CAR"
expect "it passes its data threshold: 5 of 8 qualify, 4 by verified facts" '.context.eligibility | .eligible and .qualified == 5 and .verified == 4 and .parentCount == 8' "$CAR"
expect "only entities whose facts confirm the context are ranked, each with its evidence" '(.entries | length) == 5 and all(.entries[]; .qualification.attribute == "case_types" and .qualification.value == "car-accidents" and (.qualification.source.name | length) > 0)' "$CAR"
check "the context never changes a score" '.[0] == .[1]' "$(jq -n --argjson a "$(curl -sS "$CAR" | jq -c '[.entries[] | {(.entity.slug): .score}] | add')" --argjson b "$(curl -sS "$API/rankings/best-personal-injury-lawyers-in-miami-florida-demo" | jq -c '[.entries[] | {(.entity.slug): .score}] | add')" '[$a, ($b | with_entries(select(.key as $k | $a | has($k))))]')"
expect "a context below its threshold has no page, with the reasons" '.isThin and .entries == [] and (.context.eligibility.eligible | not) and (.context.eligibility.reasons | any(test("3 of the required 5")))' "$SPANISH"
expect "the unpublished context is not listed on profiles" '[.rankings[].path] | index("/rankings/florida/miami/personal-injury/spanish-speaking/") == null' "$API/lawyers/avery-example-demo"
expect "ranking entries carry the inputs they were scored on" '.entries[0].keyFacts.yearsExperience == 22' "$API/rankings/best-personal-injury-lawyers-in-miami-florida-demo"
CONTEXTS="$(wp lexranked contexts --format=json)"
check "discovery reports contexts from the data without creating them" 'any(.[]; .context == "case_type:wrongful-death" and (.status | startswith("below threshold"))) and any(.[]; .context == "case_type:car-accidents" and .status == "published")' "$CONTEXTS"
check "discovery created no rankings" '. == 3' "$(wp post list --post_type=lr_ranking --post_status=any --format=count)"
wp eval "\\LexRanked\\Core\\Plugin::services()->claims->insert( array( 'entity_id' => $(curl -sS "$API/lawyers/blake-sample-demo" | jq .id), 'entity_type' => 'lawyer', 'field_name' => 'languages', 'value' => array( 'English', 'Spanish' ), 'source_url' => 'https://example.com/demo/bar-registry', 'source_type' => 'official_registry', 'retrieved_at' => gmdate( 'c' ), 'confidence' => 0.95, 'verification_status' => 'verified' ) );" >/dev/null
wp lexranked recalculate >/dev/null
expect "new evidence moves a context towards its threshold" '.context.eligibility.qualified == 4 and .context.eligibility.verified == 1 and .isThin' "$SPANISH"

echo "==> Page eligibility engine (Etap G)"
expect "the rules are published" '.version == "pe-1.0" and (.types | keys) == ["article","comparison","hub","listing","profile","ranking"] and ([.types.ranking[].key] | index("context") != null)' "$API/page-eligibility"
expect "every ranking carries its decision with explained checks" 'all(.[]; (.eligibility.checks | length) >= 4 and (.eligibility.version == "pe-1.0"))' "$API/rankings"
expect "a demo ranking exists but is not indexed" '.eligibility.exists and (.eligibility.indexable | not) and .eligibility.reasons == ["Not indexed: demo data."] and (.isThin | not)' "$API/rankings/best-personal-injury-lawyers-in-miami-florida-demo"
expect "a ranking below its data threshold does not exist, and says only why" '(.eligibility.exists | not) and .isThin and all(.eligibility.reasons[]; startswith("Not indexed") | not)' "$SPANISH"
expect "hubs carry their decision" 'all(.[]; .eligibility.exists == (.lawyerCount >= 3)) and all(.[]; .eligibility.indexable == false)' "$API/cities"
expect "profiles carry their decision with evidence coverage" '.eligibility.exists and (.eligibility.checks[] | select(.key == "coverage") | .value >= 0.4)' "$API/lawyers/avery-example-demo"
expect "profile summaries carry the compact decision" 'all(.[]; .eligibility.exists and (.eligibility.indexable | not))' "$API/lawyers?per_page=100"
expect "comparisons exist but are never indexed" '.eligibility.exists and (.eligibility.indexable | not)' "$API/compare?type=lawyer&entities=$AVERY_E,$BLAKE_E"
check "the pages report lists every decision" 'any(.[]; .path == "/rankings/florida/miami/personal-injury/spanish-speaking/" and .exists == "no") and any(.[]; .type == "profile" and .exists == "yes")' "$(wp lexranked pages --format=json)"

echo "==> AI-readable pages (Etap H)"
expect "the methodology is live: version, last calculation, sources, update frequency" '.active.id == "v1.1" and (.updatedAt | length) > 0 and ([.sourceTiers[].tier] | unique) == [1,2,3,4,5] and ([.freshness[] | select(.category == "review_data")][0].maxAgeDays == 7) and .pageEligibility == "pe-1.0"' "$API/methodology"
expect "profiles carry an answer-first summary built from their facts" '(.aiSummary.text | startswith("Avery Example (Demo) is a Founding Partner at Harbor Example Injury Law (Demo) in Miami, Florida")) and (.aiSummary.text | test("LexRank score: [0-9.]+/100 \\(methodology v1.1\\)")) and ([.aiSummary.facts[] | select(.key == "bar_status")][0].status == "verified")' "$API/lawyers/avery-example-demo"
expect "summaries say verified only when the fact is" '(.aiSummary.text | test("Practice areas on record: Personal Injury")) and (.aiSummary.text | test("Verified case types: Car Accidents"))' "$API/lawyers/avery-example-demo"
expect "summaries never mention paid status" '(.commercial.status == "premium") and (.aiSummary.text | test("premium|sponsor|paid"; "i") | not)' "$API/lawyers/emery-mockwell-demo"
expect "rankings list the sources behind their entries, best tier first" '(.sources | length) >= 2 and .sources[0].tier == 1 and all(.sources[]; .facts > 0 and .entities > 0)' "$API/rankings/best-personal-injury-lawyers-in-miami-florida-demo"

echo "==> Market statistics (Etap I)"
MARKET="$API/market?location=miami&practice_area=personal-injury"
expect "market counts come from the database" '.stats.version == "mkt-1.0" and .stats.lawyers == 8 and .stats.firms == 3 and .stats.verifiedLawyers == 5 and .scope.location.name == "Miami, Florida"' "$MARKET"
expect "averages and medians carry their sample size and a calculation time" '.stats.averageRating.sample == 8 and (.stats.averageRating.value > 0) and .stats.medianReviewCount.sample == 8 and (.stats.calculatedAt | length) > 0 and (.stats.dataVerifiedAt | length) > 0' "$MARKET"
expect "the summary states computed numbers only" '(.summary | startswith("LexRanked tracks 8 lawyers and 3 law firms in personal injury law in Miami, Florida. 5 of the lawyers have verified professional data."))' "$MARKET"
expect "a place-level market names its most common practice area" '.stats.mostCommonPractice.slug == "personal-injury" and (.summary | test("most common practice area in Miami, Florida is Personal Injury"))' "$API/market?location=miami"
expect "too little data is withheld, not estimated" '.stats.lawyers == 0 and .stats.averageRating == null and .stats.medianReviewCount == null and .summary == ""' "$API/market?practice_area=car-accidents"
expect_status "unknown market" 404 "$API/market?location=atlantis"

echo "==> Commercial features (Phase 9)"
RANKING="$API/rankings/best-personal-injury-lawyers-in-miami-florida-demo"
RID="$(curl -sS "$RANKING" | jq .id)"
expect "sponsored placement is delivered separately and labelled" 'length == 1 and .[0].entity.slug == "emery-mockwell-demo" and .[0].isPaidPlacement == true and .[0].label == "Sponsored" and (.[0].disclosure | test("does not affect"))' "$API/placements?product=sponsored&ranking=$RID"
expect "featured placement for the city page" 'length == 1 and .[0].entity.slug == "coral-placeholder-attorneys-demo" and .[0].product == "featured"' "$API/placements?product=featured&location=miami"
expect "no featured placements elsewhere" 'length == 0' "$API/placements?product=featured&practice_area=personal-injury"
expect_status "featured needs exactly one page" 400 "$API/placements?product=featured&location=miami&practice_area=personal-injury"
expect "premium profile: status derived, content labelled" '.commercial.status == "premium" and .commercial.claimed and .commercial.isPaidPlacement == false and (.premiumContent.message | test("Demo premium message")) and (.premiumContent.disclosure | test("not used in the score"))' "$API/lawyers/emery-mockwell-demo"
expect "ranking entries carry no placement data" '(.entries | tostring | test("placement|premiumContent|disclosure") | not) and ([.entries[] | select(.entity.slug == "emery-mockwell-demo")] | length) == 1' "$RANKING"
check "paid status does not change the stored score" '.[0] == .[1]' "$(jq -n --argjson a "$(curl -sS "$RANKING" | jq '[.entries[] | select(.entity.slug == "emery-mockwell-demo") | .score][0]')" --argjson b "$(curl -sS "$API/lawyers/emery-mockwell-demo" | jq .ranking.score)" '[$a,$b]')"
ORDER_BEFORE="$(curl -sS "$RANKING" | jq -c '[.entries[] | [.entity.slug, .score, .position]]')"

CLAIM='{"entityType":"lawyer","entityId":BLAKE,"name":"Blake Sample","email":"blake@example.com","phone":"305-555-0111","role":"self","barState":"FL","barNumber":"DEMO-0002","message":"Test claim.","consent":true}'
BLAKE="$(curl -sS "$API/lawyers/blake-sample-demo" | jq .id)"
CLAIM="${CLAIM/BLAKE/$BLAKE}"
expect_status "claims cannot be submitted anonymously" 401 "$API/claims" -X POST -H 'Content-Type: application/json' -d "$CLAIM"
expect_status "invalid claim rejected" 400 "$API/claims" -u "apiuser:$APP_PW" -X POST -H 'Content-Type: application/json' -d "${CLAIM/\"consent\":true/\"consent\":false}"
expect "claim accepted, email confirmation pending" '.status == "pending_email"' "$API/claims" -u "apiuser:$APP_PW" -X POST -H 'Content-Type: application/json' -d "$CLAIM"
expect "repeat submission is indistinguishable and not duplicated" '.status == "pending_email"' "$API/claims" -u "apiuser:$APP_PW" -X POST -H 'Content-Type: application/json' -d "$CLAIM"
check "one claim row for the repeated submission" '. == 1' "$(wp db query "SELECT COUNT(*) FROM wp_lr_profile_claims WHERE entity_id = $BLAKE" --skip-column-names | tr -dc 0-9)"
check "only the token hash is stored" '. == 64' "$(wp db query "SELECT LENGTH(email_token_hash) FROM wp_lr_profile_claims WHERE entity_id = $BLAKE" --skip-column-names | tr -dc 0-9)"
TOKEN="$("${COMPOSE[@]}" exec -T wordpress sh -c 'cat /tmp/it-mail.log' | grep -o 'token=[A-Za-z0-9_-]*' | tail -1 | cut -d= -f2)"
check "confirmation email carries a link to the frontend" '. == 43' "${#TOKEN}"
expect_status "a wrong token is rejected" 400 "$API/claims/confirm" -u "apiuser:$APP_PW" -X POST -H 'Content-Type: application/json' -d '{"token":"AAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAA"}'
expect "email confirmed, claim waits for an editor" '.status == "pending_review"' "$API/claims/confirm" -u "apiuser:$APP_PW" -X POST -H 'Content-Type: application/json' -d "{\"token\":\"$TOKEN\"}"
expect_status "a token works once" 400 "$API/claims/confirm" -u "apiuser:$APP_PW" -X POST -H 'Content-Type: application/json' -d "{\"token\":\"$TOKEN\"}"
CLAIM_ID="$(wp db query "SELECT claim_id FROM wp_lr_profile_claims WHERE entity_id = $BLAKE" --skip-column-names | tr -dc 0-9)"
if wp lexranked claim-review "$CLAIM_ID" --approve >/dev/null 2>&1; then fail "approval without an identity check was accepted"; else pass "approval requires recording the identity check"; fi
expect "unapproved claim changes nothing public" '.commercial.status == "free"' "$API/lawyers/blake-sample-demo"
wp lexranked claim-review "$CLAIM_ID" --approve --identity=bar_record >/dev/null
expect "approved claim shows as claimed" '.commercial.status == "claimed" and .commercial.claimed and .premiumContent == null' "$API/lawyers/blake-sample-demo"
expect "claimant data never reaches the public API" '(tostring | test("blake@example.com|Test claim") | not)' "$API/lawyers/blake-sample-demo"
if wp lexranked placement-add --product=sponsored --entity="$(curl -sS "$API/lawyers/avery-example-demo" | jq .id)" --ranking="$RID" >/dev/null 2>&1; then fail "unclaimed profile was placed"; else pass "only claimed profiles can buy placements"; fi
check "claims and approvals are audited" '. >= 3' "$(wp db query "SELECT COUNT(*) FROM wp_lr_audit_log WHERE action LIKE 'claim.%'" --skip-column-names | tr -dc 0-9)"
check "claim health check" 'test("claims")' "$(wp lexranked health --format=json | jq -c '[.checks[].key]' | jq -Rs .)"

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

echo "==> Entity resolution identifiers (Etap B)"
check "research drafts are indexed by bar number" '. >= 1' "$(wp db query "SELECT COUNT(*) FROM wp_postmeta WHERE meta_key = '_lr_id_bar' AND meta_value = 'FL:1001'" --skip-column-names | tr -dc 0-9)"
DUP_ID="$(wp post create --post_type=lr_lawyer --post_status=draft --post_title="J. Sample Duplicate" --meta_input='{"_lr_bar_state":"FL","_lr_bar_number":"01001"}' --porcelain | tail -1)"
check "duplicates report finds two profiles sharing an official identifier" 'test("bar number.*likely same entity.*FL:1001")' "$(wp lexranked duplicates | grep "FL:1001" | jq -Rs .)"
wp post delete "$DUP_ID" --force >/dev/null

echo "==> AI assistance (worker against a FAKE local OpenAI endpoint)"
AI_PORT="${AI_PORT:-8097}"
node workers/research/fixtures/openai/server.mjs "$AI_PORT" &
AI_PID=$!
for _ in $(seq 1 20); do curl -s -o /dev/null "http://127.0.0.1:${AI_PORT}/" && break; sleep 0.5; done
AI_JOB="$(wp lexranked research-job content_generation --porcelain | tail -1)"
run_ai_worker() {
  env LEXRANKED_API_URL="$API" LEXRANKED_WORKER_USER=researcher LEXRANKED_WORKER_APP_PASSWORD="$WORKER_PW" \
    OPENAI_API_KEY=sk-fake-it-only OPENAI_MODEL=it-model OPENAI_BASE_URL="http://127.0.0.1:${AI_PORT}/v1" \
    LEXRANKED_WORKER_JOB_TYPES=content_generation,ai_candidate_review LEXRANKED_WORKER_ID=it-ai-worker \
    node workers/research/dist/cli.js --once >>"$DATA_DIR/worker.log" 2>&1
}
run_ai_worker || true
check "AI jobs wait while AI assistance is disabled" '.status == "pending"' "$(wp lexranked research-status "$AI_JOB" --format=json)"
wp option update lexranked_settings '{"search_rate_per_minute":5,"ai_enabled":true}' --format=json >/dev/null
run_ai_worker && pass "AI worker run exits cleanly" || fail "AI worker run failed (see $DATA_DIR/worker.log)"
check "content job completed" '.status == "completed" and .stats.drafts_ready + .stats.drafts_need_review >= 1' "$(wp lexranked research-status "$AI_JOB" --format=json)"
DRAFT_ID="$(wp post list --post_type=lr_content_draft --post_status=any --field=ID --posts_per_page=1 | tail -1)"
check "generated content is stored as a draft, never published" '. == "draft"' "\"$(wp post get "$DRAFT_ID" --field=post_status)\""
check "draft carries its facts and QA status" 'test("ready_for_review|needs_review")' "\"$(wp post meta get "$DRAFT_ID" _lr_qa_status)\""
check "draft body is built from escaped plain text" 'test("<h2>How positions are decided</h2>")' "$(wp post get "$DRAFT_ID" --field=post_content | jq -Rs .)"
check "ranking content unchanged until an editor applies the draft" '(.summary | test("LexRank methodology") | not)' "$(curl -sS "$API/rankings/best-personal-injury-lawyers-in-miami-florida-demo")"
HUB_JOB="$(wp lexranked research-job content_generation --params='{"kind":"hub","hubs":"city"}' --porcelain | tail -1)"
ART_JOB="$(wp lexranked research-job content_generation --params='{"kind":"article","topic":"How LexRanked decides ranking positions"}' --porcelain | tail -1)"
run_ai_worker && run_ai_worker && pass "hub and article content jobs ran" || fail "hub/article content jobs failed (see $DATA_DIR/worker.log)"
check "hub content job drafted the Miami page" '.status == "completed" and (.stats.drafts_ready + .stats.drafts_need_review) >= 1' "$(wp lexranked research-status "$HUB_JOB" --format=json)"
check "article content job drafted an article" '.status == "completed" and (.stats.drafts_ready + .stats.drafts_need_review) == 1' "$(wp lexranked research-status "$ART_JOB" --format=json)"
HUB_DRAFT="$(wp post list --post_type=lr_content_draft --post_status=any --meta_key=_lr_content_type --meta_value=hub_content --field=ID --posts_per_page=1 | tail -1)"
check "hub draft targets the city term" '. == "lr_location"' "\"$(wp post meta get "$HUB_DRAFT" _lr_target_taxonomy)\""
check "the generator creates no WordPress posts (only content drafts)" '. == "2"' "\"$(wp post list --post_type=post --post_status=any --format=count)\""
if grep -q "sk-fake-it-only" "$DATA_DIR/worker.log"; then fail "worker logged the OpenAI key"; else pass "OpenAI key never logged"; fi
kill "$AI_PID" >/dev/null 2>&1 || true
AI_PID=""

if [[ -n "$FRONTEND" ]]; then
  echo "==> Frontend end-to-end (Next.js against this WordPress)"
  wp option delete lexranked_settings >/dev/null 2>&1 || true
  WEB="http://127.0.0.1:${FRONTEND_PORT}"
  if curl -s -o /dev/null "$WEB/"; then
    echo "Port $FRONTEND_PORT is already in use (a leftover server?). Stop it or set FRONTEND_PORT." >&2
    exit 1
  fi
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
      REVALIDATE_SECRET="$LEXRANKED_REVALIDATE_SECRET" exec node node_modules/next/dist/bin/next start -p "$FRONTEND_PORT" >/dev/null 2>&1
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
  expect_status "guides index" 200 "$WEB/articles/"
  page_has "guides index lists the demo guide" "/articles/" "How to Read a Lawyer Ranking (Demo)"
  page_has "article has Article JSON-LD" "/articles/how-to-read-a-lawyer-ranking-demo/" '"@type":"Article"'
  page_has "demo article is noindex" "/articles/how-to-read-a-lawyer-ranking-demo/" 'content="noindex, follow"'
  page_has "article links its ranking" "/articles/how-to-read-a-lawyer-ranking-demo/" 'href="/rankings/florida/miami/personal-injury/"'
  page_has "city page shows editorial summary" "/cities/miami/" "sample city used to show how LexRanked hub pages"
  page_has "city page FAQ with FAQPage JSON-LD" "/cities/miami/" '"@type":"FAQPage"'
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
  echo "==> Renamed entities keep their links (Etap A)"
  loc="$(curl -sS -o /dev/null -w '%{http_code} %{redirect_url}' "$WEB/lawyers/drew-specimen-demo/")"
  if [[ "$loc" == 308*"/lawyers/drew-specimen-renamed-demo/" ]]; then pass "former profile URL redirects permanently to the renamed profile"; else fail "former URL: $loc"; fi
  expect_status "renamed profile page" 200 "$WEB/lawyers/drew-specimen-renamed-demo/"

  echo "==> Ranking explanations on pages (Etap D)"
  page_has "each ranking entry has a Why panel" "/rankings/florida/miami/personal-injury/" "Why #1?"
  page_has "the Why panel is built from components" "/rankings/florida/miami/personal-injury/" "ranking avg"
  page_has "methodology explains evidence-only scoring" "/methodology/" "Evidence only."

  echo "==> Comparison pages (Etap E)"
  AVERY_E="$(curl -sS "$API/lawyers/avery-example-demo" | jq .entityId)"
  BLAKE_E="$(curl -sS "$API/lawyers/blake-sample-demo" | jq .entityId)"
  expect_status "comparison page" 200 "$WEB/compare/?lawyer=$AVERY_E&lawyer=$BLAKE_E"
  page_has "comparison page is never indexed" "/compare/?lawyer=$AVERY_E&lawyer=$BLAKE_E" 'content="noindex, follow"'
  page_has "comparison page shows the side-by-side table" "/compare/?lawyer=$AVERY_E&lawyer=$BLAKE_E" "Rankings they share"
  page_has "comparison page shows sources per cell" "/compare/?lawyer=$AVERY_E&lawyer=$BLAKE_E" "Example State Bar Registry (Demo)"
  page_has "an invalid comparison explains itself" "/compare/?lawyer=$AVERY_E" "different lawyers or law firms"
  page_has "ranking links its top entries to a comparison" "/rankings/florida/miami/personal-injury/" "Compare #1 and #2"
  page_has "profile links to comparisons with its neighbours" "/lawyers/blake-sample-demo/" "Compare with #"
  # Read whole bodies before grepping: with pipefail, `curl | grep -q` fails as soon as grep stops reading early.
  sitemap_body="$(curl -sS "$WEB/sitemap.xml")"
  if grep -q "/compare" <<<"$sitemap_body"; then fail "comparison pages are in the sitemap"; else pass "comparison pages stay out of the sitemap"; fi

  echo "==> Contextual ranking pages (Etap F)"
  expect_status "contextual ranking page" 200 "$WEB/rankings/florida/miami/personal-injury/car-accidents/"
  page_has "it says who is included and on what evidence" "/rankings/florida/miami/personal-injury/car-accidents/" "Who is included:"
  page_has "cards show the context attribute with its evidence" "/rankings/florida/miami/personal-injury/car-accidents/" "Car Accidents"
  page_has "breadcrumbs lead to the broader ranking" "/rankings/florida/miami/personal-injury/car-accidents/" 'href="/rankings/florida/miami/personal-injury/"'
  page_has "the broader ranking links its narrower rankings" "/rankings/florida/miami/personal-injury/" "Narrower rankings"
  page_has "cards show key attributes" "/rankings/florida/miami/personal-injury/" "22 years experience"
  page_has "the header names the methodology the entries used" "/rankings/florida/miami/personal-injury/" "LexRank v1.1"
  expect_status "a context below its threshold is a 404" 404 "$WEB/rankings/florida/miami/personal-injury/spanish-speaking/"
  if grep -q "spanish-speaking" <<<"$(curl -sS "$WEB/sitemap.xml")"; then fail "an ineligible context is in the sitemap"; else pass "ineligible contexts stay out of the sitemap"; fi

  echo "==> Page eligibility on pages (Etap G)"
  page_has "methodology publishes when a page exists" "/methodology/" 'id="page-eligibility"'
  page_has "methodology lists the thresholds" "/methodology/" "Evidence coverage"
  expect_status "a hub below the threshold has no page" 404 "$WEB/practice-areas/car-accidents/"
  page_has "an existing demo hub is noindex" "/cities/miami/" 'content="noindex, follow"'

  echo "==> AI-readable pages (Etap H)"
  page_has "profile has the Sources & verification panel" "/lawyers/avery-example-demo/" 'id="sources-and-verification"'
  page_has "each fact shows its source tier and date" "/lawyers/avery-example-demo/" "Official / regulatory"
  page_has "profile says when its data was last verified" "/lawyers/avery-example-demo/" "Data last verified"
  page_has "profile has the answer-first summary" "/lawyers/avery-example-demo/" "At a glance"
  page_has "schema.org mirrors the visible bar admission" "/lawyers/avery-example-demo/" '"hasCredential"'
  page_has "ranking lists its sources" "/rankings/florida/miami/personal-injury/" 'id="sources"'
  page_has "ranking answers related questions from its data" "/rankings/florida/miami/personal-injury/" "Which lawyers are verified?"
  page_has "related questions link narrower rankings" "/rankings/florida/miami/personal-injury/" "list car accidents among their case types"
  page_has "methodology lists data sources" "/methodology/" 'id="data-sources"'
  page_has "methodology says how often data is updated" "/methodology/" "How often data is updated"

  echo "==> Market statistics on pages (Etap I)"
  page_has "city hub shows coverage" "/cities/miami/" "with verified professional data"
  page_has "city hub leads with its computed summary" "/cities/miami/" "LexRanked tracks 8 lawyers and 3 law firms in Miami, Florida"
  page_has "city hub shows market statistics" "/cities/miami/" 'id="market"'
  page_has "ranking shows its market statistics" "/rankings/florida/miami/personal-injury/" "Personal Injury market in Miami"

  echo "==> Data Quality on pages (Etap C)"
  page_has "profile shows the Data Quality panel" "/lawyers/avery-example-demo/" "Data quality"
  page_has "the panel says it is not a ranking" "/lawyers/avery-example-demo/" "not part of the ranking"
  page_has "methodology publishes the model live" "/methodology/" 'id="data-quality"'
  page_has "methodology lists the dimensions" "/methodology/" "Verification coverage"

  echo "==> Commercial pages (Phase 9)"
  page_has "sponsored block on the ranking page" "/rankings/florida/miami/personal-injury/" 'data-placements="sponsored"'
  page_has "sponsored block is labelled as paid" "/rankings/florida/miami/personal-injury/" "Sponsored · Paid"
  rhtml="$(curl -sS "$WEB/rankings/florida/miami/personal-injury/")"
  list_at="$(grep -bo 'class="ranking-list"' <<<"$rhtml" | head -1 | cut -d: -f1)"
  ad_at="$(grep -bo 'data-placements="sponsored"' <<<"$rhtml" | head -1 | cut -d: -f1)"
  if [[ -n "$list_at" && -n "$ad_at" && "$ad_at" -gt "$list_at" ]]; then pass "sponsored block comes after the organic list"; else fail "sponsored block position ($list_at / $ad_at)"; fi
  items="$(grep -o '<script type="application/ld+json">[^<]*' <<<"$rhtml" | sed 's/^<script[^>]*>//' | jq -s '[.[] | .. | objects | select(.["@type"] == "ItemList") | .itemListElement | length] | add')"
  if (( items == 8 )); then pass "ItemList holds the 8 organic entries only"; else fail "ItemList has $items items"; fi
  page_has "featured block on the city page" "/cities/miami/" 'data-placements="featured"'
  page_has "featured firm shown" "/cities/miami/" "Coral Placeholder Attorneys (Demo)"
  page_has "premium content labelled on the profile" "/lawyers/emery-mockwell-demo/" "Premium profile · Paid"
  page_has "premium call to action is a sponsored link" "/lawyers/emery-mockwell-demo/" 'rel="sponsored noopener"'
  page_has "claimed badge" "/lawyers/blake-sample-demo/" "Claimed by the lawyer"
  page_has "unclaimed profile offers the claim link" "/lawyers/avery-example-demo/" 'href="/claim/lawyer/avery-example-demo/"'
  expect_status "claim page" 200 "$WEB/claim/lawyer/avery-example-demo/"
  page_has "claim page is noindex" "/claim/lawyer/avery-example-demo/" 'content="noindex, follow"'
  page_has "claim page has the form" "/claim/lawyer/avery-example-demo/" 'name="barNumber"'
  expect_status "claim page for an unknown profile is 404" 404 "$WEB/claim/lawyer/does-not-exist/"
  page_has "confirm page never acts on GET" "/claim/confirm/?token=$(printf 'A%.0s' $(seq 1 43))" "Confirm my email address"
  expect_status "advertising policy" 200 "$WEB/advertising/"
  page_has "advertising policy states the rule" "/advertising/" "Payment never changes a score"
  sitemap="$(curl -sS "$WEB/sitemap.xml")"
  if grep -q "/advertising/" <<<"$sitemap" && ! grep -q "/claim/" <<<"$sitemap"; then pass "sitemap lists the policy, not claim pages"; else fail "sitemap commercial pages"; fi
  sitemap="$(curl -sS "$WEB/sitemap.xml")"
  if grep -q "/methodology/" <<<"$sitemap" && ! grep -q "demo" <<<"$sitemap"; then pass "sitemap has static pages and excludes demo pages"; else fail "sitemap content"; fi
  html="$(curl -sS "$WEB/lawyers/avery-example-demo/")"
  if grep -q "$APP_PW" <<<"$html" || grep -rqF "$APP_PW" frontend/.next/static; then fail "credentials leaked into HTML or client bundle"; else pass "no credentials in HTML or client bundle"; fi

  echo "==> Production hardening (Phase 8)"
  headers="$(curl -sSI "$WEB/")"
  if grep -qi "content-security-policy: default-src 'self'" <<<"$headers" && grep -qi "strict-transport-security" <<<"$headers"; then pass "CSP and HSTS headers"; else fail "security headers missing"; fi
  expect_status "unsigned revalidation is rejected" 401 "$WEB/api/revalidate/" -X POST -d '{"tags":["lexranked"]}'
  expect "frontend health relays CMS checks" '.cms.reachable == true and (.cms.checks | length) >= 5 and (.status | test("ok|degraded"))' "$WEB/api/health/"
  expect_status "CMS health needs credentials" 401 "$API/health"
  expect "CMS health for the API role" '.checks | map(.key) | index("database") != null' "$API/health" -u "apiuser:$APP_PW"
  check "wp lexranked health reports" 'test("Overall: (ok|warning)")' "$(wp lexranked health | tail -1 | jq -Rs .)"

  # Signed revalidation: a title change in WordPress reaches the page without waiting for ISR.
  wp option update lexranked_settings "{\"frontend_url\":\"http://host.docker.internal:${FRONTEND_PORT}\"}" --format=json >/dev/null
  RANKING_ID="$(wp post list --post_type=lr_ranking --name=best-personal-injury-lawyers-in-miami-florida-demo --field=ID | tail -1)"
  curl -sS -o /dev/null "$WEB/rankings/florida/miami/personal-injury/"
  wp post update "$RANKING_ID" --post_title="Best Personal Injury Lawyers in Miami, Florida (Demo) Updated" >/dev/null
  check "WordPress reports the frontend refresh" '.state == "ok"' "$(wp option get lexranked_revalidation_status --format=json)"
  refreshed=""
  for _ in $(seq 1 10); do
    if grep -qF "(Demo) Updated" <<<"$(curl -sS "$WEB/rankings/florida/miami/personal-injury/")"; then refreshed=1; break; fi
    sleep 1
  done
  if [[ -n "$refreshed" ]]; then pass "page refreshed within seconds of the change"; else fail "page was not refreshed after the signed webhook"; fi

  echo "==> SEO and structured-data audit (live)"
  if (cd frontend && AUDIT_BASE_URL="$WEB" AUDIT_SITE_URL=https://lexranked.com npm run audit:seo >"$DATA_DIR/seo-audit.log" 2>&1); then
    pass "live SEO audit: $(grep -o 'SEO audit: .*' "$DATA_DIR/seo-audit.log" | head -1)"
  else
    fail "live SEO audit failed:"; grep -E "^[a-z_]+ /|AssertionError" "$DATA_DIR/seo-audit.log" | head -20
  fi

  if [[ "$KEEP" != "--keep" ]]; then
    kill "$NEXT_PID" >/dev/null 2>&1 || true
    NEXT_PID=""
  fi
fi

echo "==> Editorial content API (Phase 7)"
expect "articles endpoint lists the demo guide" '[.[].slug] | index("how-to-read-a-lawyer-ranking-demo") != null' "$API/articles"
expect "article detail links its ranking" '.relatedRanking.path == "/rankings/florida/miami/personal-injury/" and .wordCount >= 300 and .isDemo' "$API/articles/how-to-read-a-lawyer-ranking-demo"
expect "city carries editorial content" '[.[] | select(.slug == "miami") | .content.faq | length] == [1]' "$API/cities"

echo "==> Payment never moves the ranking (Phase 9)"
for pid in $(wp db query "SELECT placement_id FROM wp_lr_placements" --skip-column-names); do wp lexranked placement-cancel "$pid" >/dev/null; done
for cid in $(wp db query "SELECT claim_id FROM wp_lr_profile_claims WHERE status = 'approved'" --skip-column-names); do wp lexranked claim-review "$cid" --reject >/dev/null; done
expect "no placements after cancelling" 'length == 0' "$API/placements?product=sponsored&ranking=$RID"
expect "status returns to free" '.commercial.status == "free" and .premiumContent == null' "$API/lawyers/emery-mockwell-demo"
wp lexranked recalculate >/dev/null
check "identical positions and scores with and without paid placements" '.[0] == .[1]' "$(jq -n --argjson a "$ORDER_BEFORE" --argjson b "$(curl -sS "$RANKING" | jq -c '[.entries[] | [.entity.slug, .score, .position]]')" '[$a,$b]')"

echo "==> Archiving keeps the entity ID (Etap A)"
GRAY_ID="$(curl -sS "$API/lawyers/gray-dummond-demo" | jq .id)"
GRAY_EID="$(curl -sS "$API/lawyers/gray-dummond-demo" | jq .entityId)"
wp eval "wp_trash_post( $GRAY_ID );" >/dev/null
check "trashed profile is archived, not removed" '. == "archived"' "\"$(wp db query "SELECT status FROM wp_lr_entities WHERE entity_id = $GRAY_EID" --skip-column-names | tr -d '[:space:]')\""
expect_status "archived entities are not public" 404 "$API/entities/$GRAY_EID"
wp eval "wp_untrash_post( $GRAY_ID ); wp_publish_post( $GRAY_ID );" >/dev/null
expect "restored profile keeps its entity ID" ".entityId == $GRAY_EID" "$API/lawyers/gray-dummond-demo"

echo "==> Demo purge"
wp lexranked purge-demo --yes >/dev/null
expect "purge removes all demo records" 'length == 0' "$API/lawyers"
check "purge removes demo claims and placements" '. == 0' "$(wp db query "SELECT (SELECT COUNT(*) FROM wp_lr_profile_claims) + (SELECT COUNT(*) FROM wp_lr_placements)" --skip-column-names | tr -dc 0-9)"

if (( FAILURES > 0 )); then
  echo "==> $FAILURES assertion(s) failed"
  exit 1
fi
echo "==> All integration assertions passed"
