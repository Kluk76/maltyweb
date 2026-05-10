#!/usr/bin/env bash
# backup.sh — dump MySQL maltytask DB, compress, encrypt, validate, rotate, monitor.
#
# Usage:
#   backup.sh hourly    # dump in hourly/, retention 48h
#   backup.sh daily     # dump in daily/, retention 30 days
#   backup.sh monthly   # dump in monthly/, retention 12 months
#
# Pipeline: mysqldump | gzip -9 | gpg --symmetric → *.sql.gz.gpg
# Plaintext never touches disk. Encryption: AES256/SHA512/S2K mode 3.
#
# Monitoring: healthchecks.io dead-man's-switch.
#   /start ping at startup, /<uuid> on success, /fail with log payload on error.
#   If the script doesn't ping on schedule, healthchecks.io alerts via email.
#
# Exit codes:
#   0  success
#   1  invalid usage / config
#   2  pipeline failed (mysqldump, gzip, or gpg)
#   3  validation failed (corrupt or undecryptable backup)
#   4  insufficient disk space
#
# Logs to /var/log/maltytask/backup.log (append).
# Reads MySQL credentials from ~/.my.cnf.backup (chmod 600).
# Reads GPG passphrase from ~/.backup-gpg-passphrase (chmod 600).
# Reads healthchecks.io UUIDs from ~/.healthchecks-pings.env (chmod 600).
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
GPG_PASSPHRASE_FILE="$HOME/.backup-gpg-passphrase"
HC_PINGS_FILE="$HOME/.healthchecks-pings.env"
HC_PING_BASE="https://hc-ping.com"
MIN_FILE_SIZE_BYTES=102400        # 100 KB — anti-zero-byte safety net
MIN_FREE_DISK_MB=500              # require at least 500 MB free before dump

# Retention per tier
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

# ---------------------------------------------------------------------------
# Healthchecks.io helpers
# ---------------------------------------------------------------------------
# hc_ping <suffix> [body]
#   suffix:  '' (success) | 'start' | 'fail' | '0'..'255' (exit code)
#   body:    optional payload sent as POST body (e.g. log tail on failure)
# Best-effort: never fails the script if healthchecks.io is unreachable.
hc_ping() {
  local suffix="$1"
  local body="${2:-}"
  local url="${HC_PING_BASE}/${HC_PING_UUID}"
  if [[ -n "$suffix" ]]; then
    url="${url}/${suffix}"
  fi
  if [[ -n "$body" ]]; then
    curl -fsS -m 10 --retry 3 --data-raw "$body" -o /dev/null "$url" \
      2>/dev/null || true
  else
    curl -fsS -m 10 --retry 3 -o /dev/null "$url" 2>/dev/null || true
  fi
}

# Send a /fail ping with the last 30 lines of the log, then exit.
die() {
  local code="$1"
  shift
  log "ERROR" "$*"
  if [[ -n "${HC_PING_UUID:-}" ]]; then
    local log_tail
    log_tail="$(tail -30 "$LOG_FILE" 2>/dev/null || echo 'no log available')"
    hc_ping "fail" "$log_tail"
  fi
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
# Load healthchecks.io UUIDs and pick the one for this tier
# ---------------------------------------------------------------------------
# We load this BEFORE the main log line so that die() can ping /fail even
# if pre-flight checks fail.
HC_PING_UUID=""
if [[ -f "$HC_PINGS_FILE" ]]; then
  # shellcheck disable=SC1090
  source "$HC_PINGS_FILE"
  case "$TIER" in
    hourly)  HC_PING_UUID="${HC_PING_HOURLY:-}" ;;
    daily)   HC_PING_UUID="${HC_PING_DAILY:-}" ;;
    monthly) HC_PING_UUID="${HC_PING_MONTHLY:-}" ;;
  esac
fi

# ---------------------------------------------------------------------------
# Pre-flight checks
# ---------------------------------------------------------------------------
log "INFO" "=== Starting backup (tier=${TIER}) ==="

# Send /start ping right away (before potentially-slow checks).
# This lets healthchecks.io measure execution time accurately.
if [[ -n "$HC_PING_UUID" ]]; then
  hc_ping "start"
  log "INFO" "Healthchecks.io: /start pinged"
else
  log "WARN" "Healthchecks.io: no UUID configured for tier=${TIER}, monitoring disabled"
fi

[[ -f "$MYSQL_DEFAULTS_FILE" ]] \
  || die 1 "MySQL credentials file not found: $MYSQL_DEFAULTS_FILE"
[[ "$(stat -c %a "$MYSQL_DEFAULTS_FILE")" == "600" ]] \
  || die 1 "MySQL credentials file must be chmod 600: $MYSQL_DEFAULTS_FILE"

[[ -f "$GPG_PASSPHRASE_FILE" ]] \
  || die 1 "GPG passphrase file not found: $GPG_PASSPHRASE_FILE"
[[ "$(stat -c %a "$GPG_PASSPHRASE_FILE")" == "600" ]] \
  || die 1 "GPG passphrase file must be chmod 600: $GPG_PASSPHRASE_FILE"
[[ "$(stat -c %s "$GPG_PASSPHRASE_FILE")" -gt 8 ]] \
  || die 1 "GPG passphrase file looks empty or too short: $GPG_PASSPHRASE_FILE"

[[ -d "$TIER_DIR" ]] \
  || die 1 "Backup tier dir not found: $TIER_DIR"

# Check disk space
free_mb=$(df -BM --output=avail "$BACKUP_ROOT" | tail -1 | tr -dc '0-9')
if [[ "$free_mb" -lt "$MIN_FREE_DISK_MB" ]]; then
  die 4 "Insufficient disk space: ${free_mb}MB free, need ${MIN_FREE_DISK_MB}MB"
fi
log "INFO" "Disk space OK: ${free_mb}MB free in ${BACKUP_ROOT}"

# ---------------------------------------------------------------------------
# Dump + compress + encrypt (streaming, plaintext never touches disk)
# ---------------------------------------------------------------------------
ts="$(date -u +'%Y-%m-%d_%H%M%S')"
out_file="${TIER_DIR}/${DB_NAME}_${ts}_${TIER}.sql.gz.gpg"

log "INFO" "Dumping + compressing + encrypting to ${out_file}"

if ! mysqldump --defaults-file="$MYSQL_DEFAULTS_FILE" "$DB_NAME" \
     | gzip -9 \
     | gpg --batch --yes --symmetric \
           --cipher-algo AES256 \
           --digest-algo SHA512 \
           --s2k-mode 3 \
           --s2k-count 65011712 \
           --passphrase-file "$GPG_PASSPHRASE_FILE" \
           --output "$out_file"; then
  rm -f "$out_file"
  die 2 "Pipeline failed (mysqldump | gzip | gpg returned non-zero)"
fi

# ---------------------------------------------------------------------------
# Validation
# ---------------------------------------------------------------------------
log "INFO" "Validating backup"

size_bytes=$(stat -c %s "$out_file")
if [[ "$size_bytes" -lt "$MIN_FILE_SIZE_BYTES" ]]; then
  rm -f "$out_file"
  die 3 "Backup too small: ${size_bytes} bytes (min ${MIN_FILE_SIZE_BYTES})"
fi

if ! head -c 4 "$out_file" | xxd | head -1 | grep -qE '^00000000: (8c|c1|85)'; then
  rm -f "$out_file"
  die 3 "Backup does not look like a valid GPG message (bad magic bytes)"
fi

if ! gpg --batch --yes --decrypt \
         --passphrase-file "$GPG_PASSPHRASE_FILE" \
         "$out_file" 2>/dev/null \
     | gunzip -c 2>/dev/null \
     | tail -5 \
     | grep -q "Dump completed on"; then
  rm -f "$out_file"
  die 3 "Round-trip validation failed: cannot decrypt + decompress + find sentinel"
fi

size_human=$(du -h "$out_file" | cut -f1)
log "INFO" "Backup validated (decrypt+gunzip+sentinel OK): ${out_file} (${size_human})"

# ---------------------------------------------------------------------------
# Retention / cleanup
# ---------------------------------------------------------------------------
log "INFO" "Applying retention policy for tier=${TIER}"

case "$TIER" in
  hourly)
    deleted=$(find "$TIER_DIR" -maxdepth 1 -name "${DB_NAME}_*.sql.gz.gpg" \
              -type f -mmin "+$((RETENTION_HOURLY_HOURS * 60))" -delete -print | wc -l)
    log "INFO" "Hourly retention: deleted ${deleted} file(s) older than ${RETENTION_HOURLY_HOURS}h"
    ;;
  daily)
    deleted=$(find "$TIER_DIR" -maxdepth 1 -name "${DB_NAME}_*.sql.gz.gpg" \
              -type f -mtime "+${RETENTION_DAILY_DAYS}" -delete -print | wc -l)
    log "INFO" "Daily retention: deleted ${deleted} file(s) older than ${RETENTION_DAILY_DAYS}d"
    ;;
  monthly)
    deleted=$(find "$TIER_DIR" -maxdepth 1 -name "${DB_NAME}_*.sql.gz.gpg" \
              -type f -mtime "+$((RETENTION_MONTHLY_MONTHS * 30))" -delete -print | wc -l)
    log "INFO" "Monthly retention: deleted ${deleted} file(s) older than ${RETENTION_MONTHLY_MONTHS}m"
    ;;
esac

# ---------------------------------------------------------------------------
# Done — success ping
# ---------------------------------------------------------------------------
log "INFO" "=== Backup completed successfully (tier=${TIER}) ==="

if [[ -n "$HC_PING_UUID" ]]; then
  hc_ping "" "Backup ${TIER} OK: ${size_human}"
  log "INFO" "Healthchecks.io: success pinged"
fi

exit 0
