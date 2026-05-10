#!/usr/bin/env bash
# restore.sh — restore an encrypted maltytask MySQL backup with safety rails.
#
# Modes:
#   Default (safe):   restore to a temp DB `maltytask_restore_<timestamp>`.
#   --target <db>:    restore to a specific DB. If --target=maltytask (prod),
#                     extra confirmation is required.
#
# Usage:
#   restore.sh                                    # interactive: list recent backups, pick one, restore to temp DB
#   restore.sh <file.sql.gz.gpg>                  # restore given file to temp DB
#   restore.sh <file> --target maltytask_test     # restore to a named DB (prompts confirmation)
#   restore.sh <file> --target maltytask          # restore to PROD (triple confirmation + pre-backup)
#   restore.sh --dry-run <file>                   # show what would happen, don't touch anything
#   restore.sh --help
#
# Safety rails:
#   1. Confirmation required for any target DB (type the DB name exactly).
#   2. If target DB exists, automatic pre-restore backup (cannot be skipped).
#   3. Validation pre-restore: file size, GPG magic bytes, decrypt+gunzip+sentinel.
#   4. Default target is a unique temp DB — never overwrites unless --target.
#   5. `maltytask` (prod) target requires *additional* confirmation.
#
# Exit codes:
#   0  success
#   1  invalid usage / config
#   2  validation failed (corrupt or undecryptable backup)
#   3  user cancelled at confirmation
#   4  restore failed (mysql command returned non-zero)
#   5  pre-restore backup failed
#
# Logs to /var/log/maltytask/restore.log (append).
# Reads MySQL restore credentials from ~/.my.cnf.restore (chmod 600).
# Reads MySQL backup credentials from ~/.my.cnf.backup (chmod 600).
# Reads GPG passphrase from ~/.backup-gpg-passphrase (chmod 600).
#
# Expected to be run by the `ubuntu` user.
# Deployed via bin/deploy.sh from the maltyweb repo.

set -euo pipefail

# ---------------------------------------------------------------------------
# Config
# ---------------------------------------------------------------------------
PROD_DB_NAME="maltytask"
BACKUP_ROOT="/var/backups/maltytask"
LOG_FILE="/var/log/maltytask/restore.log"
MYSQL_RESTORE_DEFAULTS="$HOME/.my.cnf.restore"
MYSQL_BACKUP_DEFAULTS="$HOME/.my.cnf.backup"
GPG_PASSPHRASE_FILE="$HOME/.backup-gpg-passphrase"
MIN_FILE_SIZE_BYTES=102400

# Parsed flags
SOURCE_FILE=""
TARGET_DB=""
DRY_RUN=0

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

usage() {
  sed -n '2,/^$/p' "$0" | sed 's/^# \{0,1\}//'
  exit "${1:-0}"
}

# ---------------------------------------------------------------------------
# Argument parsing
# ---------------------------------------------------------------------------
while [[ $# -gt 0 ]]; do
  case "$1" in
    --help|-h)
      usage 0
      ;;
    --dry-run)
      DRY_RUN=1
      shift
      ;;
    --target)
      [[ $# -ge 2 ]] || die 1 "--target requires a database name"
      TARGET_DB="$2"
      shift 2
      ;;
    --target=*)
      TARGET_DB="${1#*=}"
      shift
      ;;
    -*)
      die 1 "Unknown option: $1 (try --help)"
      ;;
    *)
      [[ -z "$SOURCE_FILE" ]] || die 1 "Multiple source files given. Pass only one."
      SOURCE_FILE="$1"
      shift
      ;;
  esac
done

# Default target: a unique temp DB.
if [[ -z "$TARGET_DB" ]]; then
  TARGET_DB="${PROD_DB_NAME}_restore_$(date -u +'%Y%m%d_%H%M%S')"
fi

# ---------------------------------------------------------------------------
# Pre-flight: credentials and passphrase files
# ---------------------------------------------------------------------------
[[ -f "$MYSQL_RESTORE_DEFAULTS" ]] \
  || die 1 "MySQL restore credentials file not found: $MYSQL_RESTORE_DEFAULTS"
[[ "$(stat -c %a "$MYSQL_RESTORE_DEFAULTS")" == "600" ]] \
  || die 1 "MySQL restore credentials file must be chmod 600: $MYSQL_RESTORE_DEFAULTS"

[[ -f "$GPG_PASSPHRASE_FILE" ]] \
  || die 1 "GPG passphrase file not found: $GPG_PASSPHRASE_FILE"
[[ "$(stat -c %a "$GPG_PASSPHRASE_FILE")" == "600" ]] \
  || die 1 "GPG passphrase file must be chmod 600: $GPG_PASSPHRASE_FILE"

# ---------------------------------------------------------------------------
# Interactive backup picker (if no source file given)
# ---------------------------------------------------------------------------
pick_backup_interactively() {
  log "INFO" "No source file given. Listing recent backups..."

  # Collect all backups, newest first.
  mapfile -t backups < <(
    find "$BACKUP_ROOT" -type f -name "*.sql.gz.gpg" \
      -printf '%T@ %p\n' 2>/dev/null \
      | sort -rn \
      | awk '{ $1=""; sub(/^ /, ""); print }' \
      | head -20
  )

  if [[ ${#backups[@]} -eq 0 ]]; then
    die 1 "No backups found under $BACKUP_ROOT"
  fi

  echo "" >&2
  echo "Recent backups (newest first):" >&2
  echo "" >&2
  local i=1
  for b in "${backups[@]}"; do
    local sz
    sz="$(du -h "$b" 2>/dev/null | cut -f1)"
    printf "  %2d) %s  (%s)\n" "$i" "$b" "$sz" >&2
    i=$((i+1))
  done
  echo "" >&2

  local choice
  read -r -p "Pick a backup by number (or q to quit): " choice
  if [[ "$choice" =~ ^[Qq]$ ]]; then
    die 3 "Cancelled by user at backup selection"
  fi
  if ! [[ "$choice" =~ ^[0-9]+$ ]] || (( choice < 1 || choice > ${#backups[@]} )); then
    die 1 "Invalid choice: $choice"
  fi

  SOURCE_FILE="${backups[$((choice-1))]}"
  log "INFO" "User picked: $SOURCE_FILE"
}

if [[ -z "$SOURCE_FILE" ]]; then
  pick_backup_interactively
fi

# ---------------------------------------------------------------------------
# Pre-flight: source file
# ---------------------------------------------------------------------------
[[ -f "$SOURCE_FILE" ]] || die 1 "Source file not found: $SOURCE_FILE"
[[ -r "$SOURCE_FILE" ]] || die 1 "Source file not readable: $SOURCE_FILE"

size_bytes=$(stat -c %s "$SOURCE_FILE")
if [[ "$size_bytes" -lt "$MIN_FILE_SIZE_BYTES" ]]; then
  die 2 "Source file too small to be a valid backup: ${size_bytes} bytes"
fi

# Validate GPG magic bytes
if ! head -c 4 "$SOURCE_FILE" | xxd | head -1 | grep -qE '^00000000: (8c|c1|85)'; then
  die 2 "Source file does not look like a valid GPG message (bad magic bytes)"
fi

# Round-trip validation: decrypt + decompress + verify sentinel
log "INFO" "Validating source file (decrypt + gunzip + sentinel)..."
if ! gpg --batch --yes --decrypt \
         --passphrase-file "$GPG_PASSPHRASE_FILE" \
         "$SOURCE_FILE" 2>/dev/null \
     | gunzip -c 2>/dev/null \
     | tail -5 \
     | grep -q "Dump completed on"; then
  die 2 "Round-trip validation failed: cannot decrypt + decompress + find sentinel"
fi
log "INFO" "Source file validated"

# ---------------------------------------------------------------------------
# Show the plan
# ---------------------------------------------------------------------------
size_human=$(du -h "$SOURCE_FILE" | cut -f1)

echo "" >&2
echo "═══════════════════════════════════════════════════════════════════════" >&2
echo "RESTORE PLAN" >&2
echo "═══════════════════════════════════════════════════════════════════════" >&2
echo "  Source file: $SOURCE_FILE" >&2
echo "  Size:        $size_human" >&2
echo "  Target DB:   $TARGET_DB" >&2
echo "  Mode:        $([[ "$DRY_RUN" -eq 1 ]] && echo "DRY-RUN (no changes)" || echo "APPLY")" >&2

# Does the target DB exist?
target_exists=0
if mysql --defaults-file="$MYSQL_RESTORE_DEFAULTS" \
        -N -e "SHOW DATABASES LIKE '$TARGET_DB';" 2>/dev/null | grep -q "^${TARGET_DB}$"; then
  target_exists=1
  echo "  Target state: EXISTS (will be overwritten)" >&2
else
  echo "  Target state: does not exist (will be created)" >&2
fi

# Prod target → extra warning
prod_warning=0
if [[ "$TARGET_DB" == "$PROD_DB_NAME" ]]; then
  prod_warning=1
  echo "" >&2
  echo "  ⚠️  ⚠️  ⚠️   PRODUCTION DATABASE  ⚠️  ⚠️  ⚠️" >&2
  echo "  You are about to OVERWRITE the live brewery database." >&2
  echo "  All current data in '$PROD_DB_NAME' will be REPLACED." >&2
fi

if [[ "$target_exists" -eq 1 ]]; then
  echo "" >&2
  echo "  Pre-restore backup will be taken automatically (cannot be skipped)" >&2
fi

echo "═══════════════════════════════════════════════════════════════════════" >&2
echo "" >&2

if [[ "$DRY_RUN" -eq 1 ]]; then
  log "INFO" "DRY-RUN complete. No changes made."
  exit 0
fi

# ---------------------------------------------------------------------------
# Confirmation
# ---------------------------------------------------------------------------
echo "Type the EXACT target DB name to confirm: $TARGET_DB" >&2
read -r -p "> " typed
if [[ "$typed" != "$TARGET_DB" ]]; then
  die 3 "Confirmation does not match. Cancelled."
fi

if [[ "$prod_warning" -eq 1 ]]; then
  echo "" >&2
  echo "Additional confirmation for PRODUCTION restore." >&2
  echo "Type exactly: yes-overwrite-production" >&2
  read -r -p "> " typed2
  if [[ "$typed2" != "yes-overwrite-production" ]]; then
    die 3 "Production confirmation does not match. Cancelled."
  fi
fi

# ---------------------------------------------------------------------------
# Pre-restore backup (mandatory if target exists)
# ---------------------------------------------------------------------------
if [[ "$target_exists" -eq 1 ]]; then
  pre_backup_dir="${BACKUP_ROOT}/pre-restore"
  mkdir -p "$pre_backup_dir"
  ts="$(date -u +'%Y-%m-%d_%H%M%S')"
  pre_backup_file="${pre_backup_dir}/${TARGET_DB}_${ts}_pre-restore.sql.gz.gpg"

  log "INFO" "Taking pre-restore backup of '$TARGET_DB' to: $pre_backup_file"

  if ! mysqldump --defaults-file="$MYSQL_BACKUP_DEFAULTS" "$TARGET_DB" \
       | gzip -9 \
       | gpg --batch --yes --symmetric \
             --cipher-algo AES256 --digest-algo SHA512 \
             --s2k-mode 3 --s2k-count 65011712 \
             --passphrase-file "$GPG_PASSPHRASE_FILE" \
             --output "$pre_backup_file"; then
    rm -f "$pre_backup_file"
    die 5 "Pre-restore backup failed. Aborting before any change to '$TARGET_DB'."
  fi

  pre_sz=$(du -h "$pre_backup_file" | cut -f1)
  log "INFO" "Pre-restore backup OK: $pre_backup_file ($pre_sz)"
fi

# ---------------------------------------------------------------------------
# Restore
# ---------------------------------------------------------------------------
log "INFO" "Starting restore: $SOURCE_FILE → $TARGET_DB"

# Create target DB (or recreate it if it exists).
mysql --defaults-file="$MYSQL_RESTORE_DEFAULTS" -e "
  DROP DATABASE IF EXISTS \`$TARGET_DB\`;
  CREATE DATABASE \`$TARGET_DB\` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
" || die 4 "Failed to (re)create target DB '$TARGET_DB'"
log "INFO" "Target DB '$TARGET_DB' created (or recreated)"

# Stream: gpg → gunzip → mysql
if ! gpg --batch --yes --decrypt \
         --passphrase-file "$GPG_PASSPHRASE_FILE" \
         "$SOURCE_FILE" \
     | gunzip -c \
     | mysql --defaults-file="$MYSQL_RESTORE_DEFAULTS" "$TARGET_DB"; then
  die 4 "Restore pipeline failed (gpg | gunzip | mysql returned non-zero)"
fi

# ---------------------------------------------------------------------------
# Post-restore sanity check
# ---------------------------------------------------------------------------
log "INFO" "Verifying restored data..."
table_count=$(mysql --defaults-file="$MYSQL_RESTORE_DEFAULTS" \
                    -N -e "SELECT COUNT(*) FROM information_schema.tables WHERE table_schema='$TARGET_DB';")
log "INFO" "Tables in '$TARGET_DB': $table_count"

if [[ "$table_count" -lt 1 ]]; then
  die 4 "Restore appears empty: 0 tables in '$TARGET_DB'"
fi

# ---------------------------------------------------------------------------
# Done
# ---------------------------------------------------------------------------
log "INFO" "=== Restore completed successfully: '$SOURCE_FILE' → '$TARGET_DB' ($table_count tables) ==="

echo "" >&2
echo "✅ Restore complete." >&2
echo "   Target DB: $TARGET_DB" >&2
echo "   Tables:    $table_count" >&2
if [[ "$target_exists" -eq 1 ]]; then
  echo "   Pre-restore backup: $pre_backup_file" >&2
fi
echo "" >&2
echo "Note: temp restore DBs are NOT auto-dropped. To clean up:" >&2
echo "   mysql --defaults-file=$MYSQL_RESTORE_DEFAULTS -e \"DROP DATABASE \\\`$TARGET_DB\\\`;\"" >&2
echo "" >&2

exit 0
