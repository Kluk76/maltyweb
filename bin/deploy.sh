#!/usr/bin/env bash
# deploy.sh — push local code to the VPS via rsync.
#
# Pushes:  public/ app/ db/ scripts/
# Excludes: config/ (server-side secret), .git/, venvs, caches
#
# Usage:
#   bin/deploy.sh                    # dry-run maltyweb app (default)
#   bin/deploy.sh --apply            # apply maltyweb app changes
#   bin/deploy.sh --apply-pipeline   # sync maltytask Node pipeline to VPS
#                                    # (scripts/ lib/ package*.json → /opt/maltytask-pipeline/)
#                                    # then runs npm ci on VPS as maltytask user

set -euo pipefail

ROOT="$(cd "$(dirname "$0")/.." && pwd)"
VPS_HOST="ubuntu@83.228.215.243"
VPS_PATH="/var/www/maltytask"

# ── maltytask Node pipeline deploy ────────────────────────────────────────────
# Source repo lives at a sibling path on the operator's workstation.
# Target on VPS: /opt/maltytask-pipeline/  (Node scripts + lib, no secrets/data)
PIPELINE_SRC="$(cd "$ROOT/../maltytask" 2>/dev/null && pwd || true)"
PIPELINE_DST="/opt/maltytask-pipeline"

if [[ "${1:-}" == "--apply-pipeline" ]]; then
  if [[ -z "$PIPELINE_SRC" || ! -d "$PIPELINE_SRC" ]]; then
    echo "ERROR: maltytask source not found at $(dirname "$ROOT")/maltytask" >&2
    exit 1
  fi
  echo "→ APPLYING pipeline changes: $PIPELINE_SRC → $VPS_HOST:$PIPELINE_DST"

  for dir in scripts lib; do
    rsync -avz \
      --exclude '.git/' \
      --exclude 'node_modules/' \
      --exclude '*.js.map' \
      --rsync-path="sudo rsync" \
      -e "ssh -o BatchMode=yes" \
      "$PIPELINE_SRC/$dir/" \
      "$VPS_HOST:$PIPELINE_DST/$dir/"
  done

  for file in package.json package-lock.json; do
    rsync -avz \
      --rsync-path="sudo rsync" \
      -e "ssh -o BatchMode=yes" \
      "$PIPELINE_SRC/$file" \
      "$VPS_HOST:$PIPELINE_DST/$file"
  done

  echo "→ fixing ownership on $PIPELINE_DST"
  ssh -o BatchMode=yes "$VPS_HOST" \
    "sudo chown -R maltytask:www-data $PIPELINE_DST && \
     sudo find $PIPELINE_DST -type d -exec chmod 755 {} \; && \
     sudo find $PIPELINE_DST -type f -exec chmod 644 {} \; && \
     sudo chown root:root $PIPELINE_DST/ingest-one-local.sh $PIPELINE_DST/ingest-one.sh $PIPELINE_DST/ingest-one-local-commit.sh && \
     sudo chmod 755 $PIPELINE_DST/ingest-one-local.sh $PIPELINE_DST/ingest-one.sh $PIPELINE_DST/ingest-one-local-commit.sh"

  echo "→ running npm ci on VPS (as maltytask, includes devDeps for tsx)"
  ssh -o BatchMode=yes "$VPS_HOST" \
    "sudo -u maltytask bash -c 'HOME=/var/www/maltytask NVM_DIR=/var/www/maltytask/.nvm \
       . /var/www/maltytask/.nvm/nvm.sh && \
       cd $PIPELINE_DST && npm ci' 2>&1"

  echo "→ restoring exec bits on node_modules/.bin and platform binaries"
  ssh -o BatchMode=yes "$VPS_HOST" \
    "sudo find $PIPELINE_DST/node_modules/.bin -type l | while read l; do \
       t=\$(readlink -f \"\$l\"); [ -f \"\$t\" ] && sudo chmod +x \"\$t\"; \
     done && \
     sudo find $PIPELINE_DST/node_modules/@esbuild -name 'esbuild' -type f -exec chmod +x {} \;"

  echo "→ setting group-write + sgid on data/"
  ssh -o BatchMode=yes "$VPS_HOST" \
    "sudo chmod -R g+w $PIPELINE_DST/data && \
     sudo chmod g+s $PIPELINE_DST/data && \
     sudo chmod g+s $PIPELINE_DST/data/ocr-cache 2>/dev/null || true"

  echo "✓ pipeline deploy complete (exec bits + data/ perms restored)"
  exit 0
fi

# ── maltyweb PHP app deploy ────────────────────────────────────────────────────
DRY="--dry-run"
if [[ "${1:-}" == "--apply" ]]; then
  DRY=""
  echo "→ APPLYING changes to $VPS_HOST:$VPS_PATH"
else
  echo "→ DRY-RUN (use --apply to actually push)"
fi

for dir in public app db scripts data; do
  [[ -d "$ROOT/$dir" ]] || continue
  rsync -avz $DRY \
    --exclude '.git/' \
    --exclude '__pycache__/' \
    --exclude '.venv/' \
    --rsync-path="sudo rsync" \
    -e "ssh -o BatchMode=yes" \
    "$ROOT/$dir/" \
    "$VPS_HOST:$VPS_PATH/$dir/"
done

if [[ -z "$DRY" ]]; then
  echo "→ fixing ownership + perms on remote"
  ssh -o BatchMode=yes "$VPS_HOST" \
    "sudo chown -R maltytask:www-data $VPS_PATH/public $VPS_PATH/app $VPS_PATH/db $VPS_PATH/scripts && \
     sudo find $VPS_PATH/public $VPS_PATH/app $VPS_PATH/db $VPS_PATH/scripts -type d -exec chmod 2755 {} \; && \
     sudo find $VPS_PATH/public $VPS_PATH/app $VPS_PATH/db $VPS_PATH/scripts -type f -exec chmod 644 {} \; && \
     sudo find $VPS_PATH/scripts/db -type f -name '*.sh' -exec chmod 755 {} \;"

  echo "→ installing cron schedules from db/cron/*.cron"
  ssh -o BatchMode=yes "$VPS_HOST" \
    "for f in $VPS_PATH/db/cron/*.cron; do \
       name=\$(basename \"\$f\" .cron); \
       sudo install -o root -g root -m 0644 \"\$f\" \"/etc/cron.d/\$name\"; \
       echo \"   - installed /etc/cron.d/\$name\"; \
     done"

  echo "✓ deploy complete"
fi
