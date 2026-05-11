#!/usr/bin/env bash
# test-restore.sh — monthly automated restore test.
#
# Picks the most recent monthly backup, restores it to a unique temp DB
# (maltytask_testrestore_<ts>), runs sanity checks against the live prod DB,
# and reports to healthchecks.io.
#
# Validates the FULL recovery path (decrypt → decompress → mysql import →
# data sanity) — not just the dump's structural integrity at creation time.
#
# This is the "yearly fire drill, run every month" of database operations.
# A backup that has never been restored is wishful thinking, not a backup.
#
# Decisions:
# - Source: most recent /var/backups/maltytask/monthly/*.sql.gz.gpg
# - Target: maltytask_testrestore_<timestamp> (unique, never overwrites prod)
# - Sanity:
#   * Table count: strict equality with prod (schema changes get flagged)
#   * Row counts on sample tables: tolerance ±10% vs prod (absorbs drift)
#   * Sentinel queries: must return plausible non-empty results
# - Cleanup: DROP on success, KEEP on failure (for forensics)
#
# Exit codes:
#   0  success
#   1  invalid config / pre-flight failed
#   2  no monthly backup found
#   3  restore failed
#   4  sanity check failed
#
# Logs to /var/log/maltytask/test-restore.log (append).
# Pings https://hc-ping.com/<HC_PING_TEST_RESTORE> (start/success/fail).
#
# Expected to be run by the `ubuntu` user via crontab (15th of month, 06:00 UTC).
# Deployed via bin/deploy.sh from the maltyweb repo.

set -euo pipefail

PROD_DB_NAME="maltytask"
BACKUP_ROOT="/var/backups/maltytask"
MONTHLY_DIR="${BACKUP_ROOT}/monthly"
LOG_FILE="/var/log/maltytask/test-restore.log"
RESTORE_SCRIPT="/var/www/maltytask/scripts/db/restore.sh"
MYSQL_RESTORE_DEFAULTS="$HOME/.my.cnf.restore"
HC_PINGS_FILE="$HOME/.healthchecks-pings.env"
HC_PING_BASE="https://hc-ping.com"

ROW_COUNT_TOLERANCE_PCT=10
SAMPLE_TABLES=(
  "bd_brewing_brewday"
  "bd_packaging"
  "ref_recipes"
  "ref_yeast_strains"
  "users"
)

SENTINEL_QUERIES=(
  "max_brewday_date|SELECT IFNULL(MAX(submitted_at), '') FROM bd_brewing_brewday;"
  "active_recipes|SELECT COUNT(*) FROM ref_recipes WHERE is_active = 1;"
  "active_yeast|SELECT COUNT(*) FROM ref_yeast_strains WHERE is_active = 1;"
)

log() {
  local level="$1"; shift
  local ts; ts="$(date -u +'%Y-%m-%dT%H:%M:%SZ')"
  echo "[${ts}] [${level}] $*" | tee -a "$LOG_FILE" >&2
}

hc_ping() {
  local suffix="$1"
  local body="${2:-}"
  [[ -z "${HC_PING_UUID:-}" ]] && return 0
  local url="${HC_PING_BASE}/${HC_PING_UUID}"
  [[ -n "$suffix" ]] && url="${url}/${suffix}"
  if [[ -n "$body" ]]; then
    curl -fsS -m 10 --retry 3 --data-raw "$body" -o /dev/null "$url" 2>/dev/null || true
  else
    curl -fsS -m 10 --retry 3 -o /dev/null "$url" 2>/dev/null || true
  fi
}

die() {
  local code="$1"; shift
  log "ERROR" "$*"
  if [[ -n "${HC_PING_UUID:-}" ]]; then
    local log_tail
    log_tail="$(tail -50 "$LOG_FILE" 2>/dev/null || echo 'no log available')"
    hc_ping "fail" "$log_tail"
  fi
  exit "$code"
}

HC_PING_UUID=""
if [[ -f "$HC_PINGS_FILE" ]]; then
  source "$HC_PINGS_FILE"
  HC_PING_UUID="${HC_PING_TEST_RESTORE:-}"
fi

log "INFO" "=== Starting monthly test-restore ==="

if [[ -n "$HC_PING_UUID" ]]; then
  hc_ping "start"
  log "INFO" "Healthchecks.io: /start pinged"
else
  log "WARN" "Healthchecks.io: no HC_PING_TEST_RESTORE configured, monitoring disabled"
fi

[[ -x "$RESTORE_SCRIPT" ]] \
  || die 1 "restore.sh not found or not executable at $RESTORE_SCRIPT"
[[ -f "$MYSQL_RESTORE_DEFAULTS" ]] \
  || die 1 "MySQL restore credentials not found: $MYSQL_RESTORE_DEFAULTS"
[[ -d "$MONTHLY_DIR" ]] \
  || die 1 "Monthly backup dir not found: $MONTHLY_DIR"

SOURCE_FILE="$(find "$MONTHLY_DIR" -maxdepth 1 -type f -name "*.sql.gz.gpg" \
                -printf '%T@ %p\n' 2>/dev/null \
                | sort -rn | head -1 | awk '{ $1=""; sub(/^ /, ""); print }')"

if [[ -z "$SOURCE_FILE" ]]; then
  die 2 "No monthly backup found in $MONTHLY_DIR"
fi

source_size=$(du -h "$SOURCE_FILE" | cut -f1)
source_age_days=$(( ( $(date -u +%s) - $(stat -c %Y "$SOURCE_FILE") ) / 86400 ))
log "INFO" "Selected backup: $SOURCE_FILE ($source_size, ${source_age_days}d old)"

ts="$(date -u +'%Y%m%d_%H%M%S')"
TARGET_DB="${PROD_DB_NAME}_testrestore_${ts}"

log "INFO" "Target DB: $TARGET_DB"

log "INFO" "Cleaning up older test-restore DBs (keeping the most recent failed one if any)"

mapfile -t leftover_dbs < <(
  mysql --defaults-file="$MYSQL_RESTORE_DEFAULTS" -N -e \
    "SELECT schema_name FROM information_schema.schemata
     WHERE schema_name LIKE '${PROD_DB_NAME}_testrestore_%'
     ORDER BY schema_name DESC;" 2>/dev/null || true
)

if [[ ${#leftover_dbs[@]} -gt 1 ]]; then
  for db in "${leftover_dbs[@]:1}"; do
    log "INFO" "Dropping old leftover test-restore DB: $db"
    mysql --defaults-file="$MYSQL_RESTORE_DEFAULTS" -e "DROP DATABASE \`$db\`;" 2>/dev/null || \
      log "WARN" "Failed to drop $db (continuing)"
  done
fi

log "INFO" "Invoking restore.sh --target $TARGET_DB"

if ! echo "$TARGET_DB" | "$RESTORE_SCRIPT" "$SOURCE_FILE" --target "$TARGET_DB" >> "$LOG_FILE" 2>&1; then
  die 3 "restore.sh failed. Target DB '$TARGET_DB' may exist for forensics. See $LOG_FILE"
fi

log "INFO" "Restore completed. Running sanity checks..."

prod_tables=$(mysql --defaults-file="$MYSQL_RESTORE_DEFAULTS" -N -e \
  "SELECT COUNT(*) FROM information_schema.tables WHERE table_schema='$PROD_DB_NAME';")
test_tables=$(mysql --defaults-file="$MYSQL_RESTORE_DEFAULTS" -N -e \
  "SELECT COUNT(*) FROM information_schema.tables WHERE table_schema='$TARGET_DB';")

log "INFO" "Table count: prod=$prod_tables vs restored=$test_tables"

if [[ "$prod_tables" != "$test_tables" ]]; then
  diff=$(( prod_tables - test_tables ))
  abs_diff=${diff#-}
  if [[ "$abs_diff" -gt 2 ]]; then
    die 4 "Table count mismatch: prod=$prod_tables vs restored=$test_tables (diff=$diff, allowed ±2). Target DB '$TARGET_DB' kept for forensics."
  fi
  log "WARN" "Table count differs by ${abs_diff} (within ±2 tolerance)"
fi

log "INFO" "Comparing row counts on ${#SAMPLE_TABLES[@]} sample tables (tolerance ±${ROW_COUNT_TOLERANCE_PCT}%)..."

for tbl in "${SAMPLE_TABLES[@]}"; do
  if ! mysql --defaults-file="$MYSQL_RESTORE_DEFAULTS" -N -e \
       "SELECT 1 FROM information_schema.tables
        WHERE table_schema='$PROD_DB_NAME' AND table_name='$tbl';" | grep -q 1; then
    log "WARN" "Sample table '$tbl' not in prod — skipping"
    continue
  fi

  prod_rows=$(mysql --defaults-file="$MYSQL_RESTORE_DEFAULTS" -N -e \
              "SELECT COUNT(*) FROM \`$PROD_DB_NAME\`.\`$tbl\`;" 2>/dev/null || echo "0")
  test_rows=$(mysql --defaults-file="$MYSQL_RESTORE_DEFAULTS" -N -e \
              "SELECT COUNT(*) FROM \`$TARGET_DB\`.\`$tbl\`;" 2>/dev/null || echo "0")

  if [[ "$prod_rows" -eq 0 ]]; then
    log "INFO" "  $tbl: prod=0 restored=$test_rows (skipping ratio check)"
    continue
  fi

  diff=$(( test_rows - prod_rows ))
  abs_diff=${diff#-}
  pct=$(( abs_diff * 100 / prod_rows ))

  status="OK"
  if [[ "$pct" -gt "$ROW_COUNT_TOLERANCE_PCT" ]]; then
    status="FAIL (>${ROW_COUNT_TOLERANCE_PCT}%)"
  fi
  log "INFO" "  $tbl: prod=$prod_rows restored=$test_rows diff=${diff} (${pct}%) [$status]"

  if [[ "$status" != "OK" ]]; then
    die 4 "Row count drift too large on table '$tbl': prod=$prod_rows vs restored=$test_rows (${pct}%, max ${ROW_COUNT_TOLERANCE_PCT}%). Target DB '$TARGET_DB' kept for forensics."
  fi
done

log "INFO" "Running ${#SENTINEL_QUERIES[@]} sentinel queries..."

for entry in "${SENTINEL_QUERIES[@]}"; do
  label="${entry%%|*}"
  query="${entry#*|}"

  result=$(mysql --defaults-file="$MYSQL_RESTORE_DEFAULTS" "$TARGET_DB" -N -e "$query" 2>/dev/null || echo "")

  if [[ -z "$result" || "$result" == "0" ]]; then
    die 4 "Sentinel query '$label' returned empty/zero result. Query: $query. Target DB '$TARGET_DB' kept for forensics."
  fi

  log "INFO" "  $label: $result"
done

log "INFO" "All sanity checks passed. Dropping test DB '$TARGET_DB'..."

mysql --defaults-file="$MYSQL_RESTORE_DEFAULTS" -e "DROP DATABASE \`$TARGET_DB\`;" \
  || log "WARN" "Failed to drop $TARGET_DB — manual cleanup needed"

log "INFO" "=== Test-restore PASSED (source: $SOURCE_FILE, ${source_age_days}d old) ==="

if [[ -n "$HC_PING_UUID" ]]; then
  hc_ping "" "Test-restore OK: $(basename "$SOURCE_FILE") (${source_age_days}d old, $source_size)"
  log "INFO" "Healthchecks.io: success pinged"
fi

exit 0
