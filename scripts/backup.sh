#!/usr/bin/env bash
# Back up the LexRanked WordPress: database (all tables, including lr_* evidence,
# snapshots, research data and the audit log) and uploads, with checksums and
# retention. Run from cron on the WordPress host, then copy BACKUP_DIR off-site.
#
# Usage:
#   scripts/backup.sh                      # WP-CLI on this host (WP_PATH)
#   scripts/backup.sh --docker             # local docker-compose stack
# Environment:
#   BACKUP_DIR      where archives go            (default ./backups)
#   WP_PATH         WordPress root for WP-CLI     (default /var/www/html)
#   RETENTION_DAYS  delete older backups          (default 14)
set -euo pipefail

BACKUP_DIR="${BACKUP_DIR:-./backups}"
WP_PATH="${WP_PATH:-/var/www/html}"
RETENTION_DAYS="${RETENTION_DAYS:-14}"
MODE="host"
[[ "${1:-}" == "--docker" ]] && MODE="docker"

STAMP="$(date -u +%Y%m%dT%H%M%SZ)"
TARGET="$BACKUP_DIR/lexranked-$STAMP"
umask 077 # backups contain personal and private data
mkdir -p "$TARGET"

if [[ "$MODE" == "docker" ]]; then
  ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
  cd "$ROOT"
  docker compose run --rm -T wpcli wp db export - --add-drop-table 2>/dev/null | gzip -9 >"$TARGET/database.sql.gz"
  docker compose exec -T wordpress tar -C /var/www/html/wp-content -czf - uploads >"$TARGET/uploads.tar.gz" 2>/dev/null || true
  docker compose run --rm -T wpcli wp lexranked status >"$TARGET/status.txt" 2>/dev/null || true
else
  wp --path="$WP_PATH" db export - --add-drop-table | gzip -9 >"$TARGET/database.sql.gz"
  if [[ -d "$WP_PATH/wp-content/uploads" ]]; then
    tar -C "$WP_PATH/wp-content" -czf "$TARGET/uploads.tar.gz" uploads
  fi
  wp --path="$WP_PATH" lexranked status >"$TARGET/status.txt" 2>/dev/null || true
fi

# Refuse to keep an empty or truncated dump.
if ! gzip -t "$TARGET/database.sql.gz" || [[ "$(gzip -dc "$TARGET/database.sql.gz" | head -c 200000 | grep -c 'CREATE TABLE')" -lt 5 ]]; then
  echo "Backup failed: database dump is empty or invalid ($TARGET)" >&2
  exit 1
fi
(cd "$TARGET" && sha256sum ./* >SHA256SUMS)

find "$BACKUP_DIR" -maxdepth 1 -type d -name 'lexranked-*' -mtime +"$RETENTION_DAYS" -exec rm -rf {} +

echo "{\"level\":\"info\",\"source\":\"backup\",\"target\":\"$TARGET\",\"size\":\"$(du -sh "$TARGET" | cut -f1)\"}"
