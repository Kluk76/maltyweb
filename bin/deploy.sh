#!/usr/bin/env bash
# deploy.sh — push local code to the VPS via rsync.
#
# Pushes:  public/ app/ db/ scripts/
# Excludes: config/ (server-side secret), .git/, venvs, caches
#
# Usage:
#   bin/deploy.sh             # dry-run (default — prints what would change)
#   bin/deploy.sh --apply     # actually transfer

set -euo pipefail

ROOT="$(cd "$(dirname "$0")/.." && pwd)"
VPS_HOST="ubuntu@83.228.215.243"
VPS_PATH="/var/www/maltytask"
SSH_KEY="$HOME/.ssh/maltytask_vps"

DRY="--dry-run"
if [[ "${1:-}" == "--apply" ]]; then
  DRY=""
  echo "→ APPLYING changes to $VPS_HOST:$VPS_PATH"
else
  echo "→ DRY-RUN (use --apply to actually push)"
fi

for dir in public app db scripts; do
  [[ -d "$ROOT/$dir" ]] || continue
  rsync -avz $DRY \
    --exclude '.git/' \
    --exclude '__pycache__/' \
    --exclude '.venv/' \
    --rsync-path="sudo rsync" \
    -e "ssh -i $SSH_KEY -o BatchMode=yes" \
    "$ROOT/$dir/" \
    "$VPS_HOST:$VPS_PATH/$dir/"
done

if [[ -z "$DRY" ]]; then
  echo "→ fixing ownership + perms on remote"
  ssh -i "$SSH_KEY" -o BatchMode=yes "$VPS_HOST" \
    "sudo chown -R maltytask:www-data $VPS_PATH/public $VPS_PATH/app $VPS_PATH/db $VPS_PATH/scripts && \
     sudo find $VPS_PATH/public $VPS_PATH/app $VPS_PATH/db $VPS_PATH/scripts -type d -exec chmod 2755 {} \; && \
     sudo find $VPS_PATH/public $VPS_PATH/app $VPS_PATH/db $VPS_PATH/scripts -type f -exec chmod 644 {} \;"
  echo "✓ deploy complete"
fi
