#!/usr/bin/env bash
# Memory-hygiene audit: measure a memory store and flag over-budget files.
# Usage: audit.sh <memory-root>
#   <memory-root> is either:
#     - a Type A project memory dir (contains MEMORY.md), or
#     - a Type B agent index file (<agent>-memory.md) OR its dir (<agent>-memory/)
#
# Budgets: Type A MEMORY.md < 190 lines / 30KB; Type B index < 256KB (hard),
# target < 100KB; Type B topic file split at > 80KB.
set -euo pipefail

TARGET="${1:?usage: audit.sh <memory-root>}"
KB() { echo $(( $1 / 1024 ))KB; }

flag() { # $1=bytes $2=limitBytes $3=label
  if [ "$1" -gt "$2" ]; then echo "  ⚠️  OVER ($(KB $1) > $(KB $2)) — $3"; else echo "  ✅ ok  ($(KB $1)) — $3"; fi
}

echo "=== memory-hygiene audit: $TARGET ==="

# Type A: a directory containing MEMORY.md
if [ -d "$TARGET" ] && [ -f "$TARGET/MEMORY.md" ]; then
  echo "[Type A — harness auto-recall — topic files MUST stay flat]"
  lines=$(wc -l < "$TARGET/MEMORY.md"); bytes=$(wc -c < "$TARGET/MEMORY.md")
  echo "  MEMORY.md: $lines lines, $(KB $bytes)"
  [ "$lines" -gt 190 ] && echo "  ⚠️  OVER 190-line budget — trim the index" || echo "  ✅ under 190-line budget"
  subs=$(find "$TARGET" -mindepth 1 -type d 2>/dev/null || true)
  [ -n "$subs" ] && echo "  ⚠️  SUBDIRECTORIES PRESENT (recall risk for Type A!):" && echo "$subs" || echo "  ✅ flat (no subdirs)"
  echo "  topic files: $(find "$TARGET" -maxdepth 1 -name '*.md' ! -name MEMORY.md | wc -l)"
  exit 0
fi

# Type B: an agent index file, or its directory
IDX=""; DIR=""
if [ -f "$TARGET" ]; then IDX="$TARGET"; DIR="${TARGET%.md}";
elif [ -d "$TARGET" ]; then DIR="$TARGET"; IDX="${TARGET%/}.md"; fi

echo "[Type B — agent memory — subdirectories are safe]"
if [ -f "$IDX" ]; then
  b=$(wc -c < "$IDX"); echo "  index $(basename "$IDX"): $(KB $b)"
  flag "$b" $((256*1024)) "HARD Read ceiling 256KB"
  flag "$b" $((100*1024)) "target < 100KB"
fi
if [ -d "$DIR" ]; then
  echo "  topic files (largest first; split at >80KB):"
  while read -r sz f; do flag "$sz" $((80*1024)) "$(basename "$f")"; done \
    < <(find "$DIR" -maxdepth 1 -name '*.md' -printf '%s %p\n' | sort -rn | head -12)
fi
