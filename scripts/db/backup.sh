#!/usr/bin/env bash
# backup.sh — dump MySQL maltytask DB, compress, encrypt, validate, rotate,
#             sync to Google Drive (Shared Drive), monitor.
#
# Usage:
#   backup.sh hourly    # dump in hourly/, retention 48h
#   backup.sh daily     # dump in daily/, retention 30 days
#   backup.sh monthly   # dump in monthly/, retention 12 months
#
# Pipeline: mysqldump | gzip -9 | gpg --symmetric → *.sql.gz.gpg
# Plaintext never touches disk. Encryption: AES256/SHA512/S2K mode 3.
#
# Off-site sync: after local validation, `rclone copy` to Google Drive
# Shared Drive (write-only archive, no delete propagation). 3 retries with
# exponential backoff. If sync fails after retries, the whole backup is
# reported as failed (via healthchecks.io /fail and exit code != 0).
#
# Monitoring: healthchecks.io dead-man's-switch.
#   /start ping at startup, /<uuid> on success, /fail with log payload on error.
#   If the script doesn't ping on schedule, healthchecks.io alerts via email.
#
# Exit codes:
#   0  success (local + off-site)
#   1  invalid usage / config
#   2  pipeline failed (mysqldump, gzip, or gpg)
#   3  validation failed (corrupt or undecryptable backup)
#   4  insufficient disk space
#   5  off-site sync failed (rclone)
#
# Logs to /var/log/maltytask/backup.log (append).
# Reads MySQL credentials from ~/.my.cnf.backup (chmod 600).
# Reads GPG passphrase from ~/.backup-gpg-passphrase (chmod 600).
# Reads healthchecks.io UUIDs from ~/.healthchecks-pings.env (chmod 600).
# Uses rclone remote 'gdrive-maltytask:' (config: ~/.config/rclone/rclone.conf).
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
RCLONE_REMOTE="gdrive-maltytask:db"     # mirrors local /var/backups/maltytask/
RCLONE_MAX_ATTEMPTS=3                    # number of attempts for off-site sync
RCLONE_BACKOFF_BASE=5                    # seconds, doubled each retry
MIN_FILE_SIZE_BYTES=102400               # 100 KB — anti-zero-byte safety net
MIN_FREE_DISK_MB=500                     # require at least 500 MB free before dump

# Retention per tier (local only; Drive is write-only)
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
# Off-site sync to Google Drive (Shared Drive, write-only archive)
# ---------------------------------------------------------------------------
# sync_to_drive <local_file> <tier>
#   Copies local_file to gdrive-maltytask:db/<tier>/ using `rclone copy`.
#   `copy` (not `sync`) is intentional: never delete files on Drive even if
#   they're gone locally. This makes Drive a tamper-evident write-only archive.
#
#   Retries 3× with exponential backoff (5s, 10s, 20s).
#   Returns 0 on success, non-zero on failure.
sync_to_drive() {
  local local_file="$1"
  local tier="$2"
  local filename
  filename="$(basename "$local_file")"
  local remote_path="${RCLONE_REMOTE}/${tier}/"

  local attempt=1
  local backoff="$RCLONE_BACKOFF_BASE"

  while (( attempt <= RCLONE_MAX_ATTEMPTS )); do
    log "INFO" "Drive sync attempt ${attempt}/${RCLONE_MAX_ATTEMPTS}: ${filename} → ${remote_path}"

    if rclone copy "$local_file" "$remote_path" \
         --no-traverse \
         --transfers 1 \
         --retries 1 \
         --low-level-retries 3 \
         --timeout 60s \
         --contimeout 30s \
         2>>"$LOG_FILE"; then
      log "INFO" "Drive sync OK: ${filename}"
      return 0
    fi

    log "WARN" "Drive sync attempt ${attempt} failed"
    if (( attempt < RCLONE_MAX_ATTEMPTS )); then
      log "INFO" "Sleeping ${backoff}s before retry..."
      sleep "$backoff"
      backoff=$(( backoff * 2 ))
    fi
    attempt=$(( attempt + 1 ))
  done

  return 1
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

command -v rclone >/dev/null 2>&1 \
  || die 1 "rclone is not installed or not in PATH"
[[ -f "$HOME/.config/rclone/rclone.conf" ]] \
  || die 1 "rclone config not found: ~/.config/rclone/rclone.conf"

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
# Off-site sync to Google Drive (mandatory: failure = whole backup failed)
# ---------------------------------------------------------------------------
log "INFO" "Off-site sync to Google Drive starting..."
if ! sync_to_drive "$out_file" "$TIER"; then
  die 5 "Off-site sync to Google Drive failed after ${RCLONE_MAX_ATTEMPTS} attempts. Local file kept: ${out_file}"
fi

# ---------------------------------------------------------------------------
# Retention / cleanup (LOCAL ONLY — Drive is write-only)
# ---------------------------------------------------------------------------
log "INFO" "Applying local retention policy for tier=${TIER} (Drive untouched)"

case "$TIER" in
  hourly)
    deleted=$(find "$TIER_DIR" -maxdepth 1 -name "${DB_NAME}_*.sql.gz.gpg" \
              -type f -mmin "+$((RETENTION_HOURLY_HOURS * 60))" -delete -print | wc -l)
    log "INFO" "Hourly retention: deleted ${deleted} local file(s) older than ${RETENTION_HOURLY_HOURS}h"
    ;;
  daily)
    deleted=$(find "$TIER_DIR" -maxdepth 1 -name "${DB_NAME}_*.sql.gz.gpg" \
              -type f -mtime "+${RETENTION_DAILY_DAYS}" -delete -print | wc -l)
    log "INFO" "Daily retention: deleted ${deleted} local file(s) older than ${RETENTION_DAILY_DAYS}d"
    ;;
  monthly)
    deleted=$(find "$TIER_DIR" -maxdepth 1 -name "${DB_NAME}_*.sql.gz.gpg" \
              -type f -mtime "+$((RETENTION_MONTHLY_MONTHS * 30))" -delete -print | wc -l)
    log "INFO" "Monthly retention: deleted ${deleted} local file(s) older than ${RETENTION_MONTHLY_MONTHS}m"
    ;;
esac

# ---------------------------------------------------------------------------
# Done — success ping
# ---------------------------------------------------------------------------
log "INFO" "=== Backup completed successfully (tier=${TIER}, local+offsite) ==="

if [[ -n "$HC_PING_UUID" ]]; then
  hc_ping "" "Backup ${TIER} OK: ${size_human} (local + Drive)"
  log "INFO" "Healthchecks.io: success pinged"
fi

exit 0
