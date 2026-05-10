#!/usr/bin/env bash
# backup.sh — dump MySQL maltytask DB, compress, validate, rotate.
#
# Usage:
#   backup.sh hourly    # dump in hourly/, retention 48h
#   backup.sh daily     # dump in daily/, retention 30 days
#   backup.sh monthly   # dump in monthly/, retention 12 months
#
# Exit codes:
#   0  success
#   1  invalid usage / config
#   2  mysqldump failed
#   3  validation failed (corrupt or truncated dump)
#   4  insufficient disk space
#
# Logs to /var/log/maltytask/backup.log (append).
# Reads MySQL credentials from ~/.my.cnf.backup (must be chmod 600).
#
# Expected to be run by the `ubuntu` user via crontab.
# Deployed via bin/deploy.sh from the maltyweb repo.

set -euo pipefail

# ---------------------------------------------------------------------------
# Config
# ---------------------------------------------------------------------------
DB_NAME="maltytask"
BACKUP_ROOT="/var/backups/maltytask"
LOG_FILE="/var/log/maltytask/backup.log"
MYSQL_DEFAULTS_FILE="$HOME/.my.cnf.backup"
MIN_FILE_SIZE_BYTES=102400        # 100 KB — anti-zero-byte safety net
MIN_FREE_DISK_MB=500              # require at least 500 MB free before dump

# Retention per tier (in days for hourly/daily, in months for monthly)
RETENTION_HOURLY_HOURS=48
RETENTION_DAILY_DAYS=30
RETENTION_MONTHLY_MONTHS=12

# ---------------------------------------------------------------------------
# Logging helpers
# ---------------------------------------------------------------------------
log() {
  local level="$1"
  shift
  local msg="$*"
  local ts
  ts="$(date -u +'%Y-%m-%dT%H:%M:%SZ')"
  echo "[${ts}] [${level}] ${msg}" | tee -a "$LOG_FILE" >&2
}

die() {
  local code="$1"
  shift
  log "ERROR" "$*"
  exit "$code"
}

# ---------------------------------------------------------------------------
# Argument parsing
# ---------------------------------------------------------------------------
TIER="${1:-}"
case "$TIER" in
  hourly|daily|monthly) ;;
  "")
    echo "Usage: $0 {hourly|daily|monthly}" >&2
    exit 1
    ;;
  *)
    echo "ERROR: invalid tier '${TIER}'. Must be: hourly | daily | monthly" >&2
    exit 1
    ;;
esac

TIER_DIR="${BACKUP_ROOT}/${TIER}"

# ---------------------------------------------------------------------------
# Pre-flight checks
# ---------------------------------------------------------------------------
log "INFO" "=== Starting backup (tier=${TIER}) ==="

[[ -f "$MYSQL_DEFAULTS_FILE" ]] \
  || die 1 "Credentials file not found: $MYSQL_DEFAULTS_FILE"

[[ "$(stat -c %a "$MYSQL_DEFAULTS_FILE")" == "600" ]] \
  || die 1 "Credentials file $MYSQL_DEFAULTS_FILE must be chmod 600"

[[ -d "$TIER_DIR" ]] \
  || die 1 "Backup tier dir not found: $TIER_DIR"

# Check disk space
free_mb=$(df -BM --output=avail "$BACKUP_ROOT" | tail -1 | tr -dc '0-9')
if [[ "$free_mb" -lt "$MIN_FREE_DISK_MB" ]]; then
  die 4 "Insufficient disk space: ${free_mb}MB free, need ${MIN_FREE_DISK_MB}MB"
fi
log "INFO" "Disk space OK: ${free_mb}MB free in ${BACKUP_ROOT}"

# ---------------------------------------------------------------------------
# Dump + compress
# ---------------------------------------------------------------------------
ts="$(date -u +'%Y-%m-%d_%H%M%S')"
out_file="${TIER_DIR}/${DB_NAME}_${ts}_${TIER}.sql.gz"

log "INFO" "Dumping to ${out_file}"

# Pipefail is set, so any failure in the pipeline propagates.
# mysqldump options come from --defaults-file (see ~/.my.cnf.backup).
if ! mysqldump --defaults-file="$MYSQL_DEFAULTS_FILE" "$DB_NAME" \
     | gzip -9 > "$out_file"; then
  rm -f "$out_file"
  die 2 "mysqldump failed (pipeline returned non-zero)"
fi

# ---------------------------------------------------------------------------
# Validation
# ---------------------------------------------------------------------------
log "INFO" "Validating dump"

# Check 1: file size > minimum
size_bytes=$(stat -c %s "$out_file")
if [[ "$size_bytes" -lt "$MIN_FILE_SIZE_BYTES" ]]; then
  rm -f "$out_file"
  die 3 "Dump too small: ${size_bytes} bytes (min ${MIN_FILE_SIZE_BYTES})"
fi

# Check 2: gzip integrity
if ! gunzip -t "$out_file" 2>/dev/null; then
  rm -f "$out_file"
  die 3 "Gzip integrity check failed"
fi

# Check 3: completion sentinel
if ! zcat "$out_file" | tail -5 | grep -q "Dump completed on"; then
  rm -f "$out_file"
  die 3 "Dump completion sentinel not found (truncated dump?)"
fi

size_human=$(du -h "$out_file" | cut -f1)
log "INFO" "Dump validated: ${out_file} (${size_human})"

# ---------------------------------------------------------------------------
# Retention / cleanup
# ---------------------------------------------------------------------------
log "INFO" "Applying retention policy for tier=${TIER}"

case "$TIER" in
  hourly)
    # Files older than 48 hours
    deleted=$(find "$TIER_DIR" -maxdepth 1 -name "${DB_NAME}_*.sql.gz" \
              -type f -mmin "+$((RETENTION_HOURLY_HOURS * 60))" -delete -print | wc -l)
    log "INFO" "Hourly retention: deleted ${deleted} file(s) older than ${RETENTION_HOURLY_HOURS}h"
    ;;
  daily)
    # Files older than 30 days
    deleted=$(find "$TIER_DIR" -maxdepth 1 -name "${DB_NAME}_*.sql.gz" \
              -type f -mtime "+${RETENTION_DAILY_DAYS}" -delete -print | wc -l)
    log "INFO" "Daily retention: deleted ${deleted} file(s) older than ${RETENTION_DAILY_DAYS}d"
    ;;
  monthly)
    # Files older than 12 months (approx 365 days)
    deleted=$(find "$TIER_DIR" -maxdepth 1 -name "${DB_NAME}_*.sql.gz" \
              -type f -mtime "+$((RETENTION_MONTHLY_MONTHS * 30))" -delete -print | wc -l)
    log "INFO" "Monthly retention: deleted ${deleted} file(s) older than ${RETENTION_MONTHLY_MONTHS}m"
    ;;
esac

# ---------------------------------------------------------------------------
# Done
# ---------------------------------------------------------------------------
log "INFO" "=== Backup completed successfully (tier=${TIER}) ==="
exit 0
