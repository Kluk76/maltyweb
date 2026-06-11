#!/usr/bin/env bash
# publish.sh — run by the PRIMARY developer (Kouros) to refresh the claude-brain
# snapshot from his canonical ~/.claude into this repo folder.
#
# Deliberately EXCLUDED:
#   - skill-creator        (PM-authoring tooling, not a build skill)
#   - parser-coder         (invoice parser scope; outside Louis's remit as sales dev)
#   - parser-coder-workspace
#   - *-workspace dirs     (scratch space, not distributable)
#
# Never modifies ~/.claude. Copy-out only.
set -euo pipefail

BRAIN="$(cd "$(dirname "$0")" && pwd)"
SRC="$HOME/.claude"

echo "=== claude-brain publish ==="
echo "  Source : $SRC"
echo "  Target : $BRAIN"
echo ""

# ── Skills ────────────────────────────────────────────────────────────────────
SKILLS_TO_COPY=(
    coder
    sql
    ui
    webapp-testing
    memory-hygiene
    skill-vetting
    xlsx
)

mkdir -p "$BRAIN/skills"

echo "Copying skills..."
for skill in "${SKILLS_TO_COPY[@]}"; do
    src_path="$SRC/skills/$skill"
    dst_path="$BRAIN/skills/$skill"
    if [ ! -d "$src_path" ]; then
        echo "  WARN: skill '$skill' not found at $src_path — skipping"
        continue
    fi
    rm -rf "$dst_path"
    cp -a "$src_path" "$dst_path"
    size=$(du -sh "$dst_path" | cut -f1)
    echo "  [OK] $skill  ($size)"
done

# ── Agents ────────────────────────────────────────────────────────────────────
AGENT_FILES=(
    maltyweb-pm.md
    maltyweb-tour-steward.md
    maltyweb-pm-memory.md
)

mkdir -p "$BRAIN/agents"

echo ""
echo "Copying agent files..."
for f in "${AGENT_FILES[@]}"; do
    src_path="$SRC/agents/$f"
    dst_path="$BRAIN/agents/$f"
    if [ ! -f "$src_path" ]; then
        echo "  WARN: agent file '$f' not found at $src_path — skipping"
        continue
    fi
    cp -a "$src_path" "$dst_path"
    size=$(du -sh "$dst_path" | cut -f1)
    echo "  [OK] $f  ($size)"
done

# maltyweb-pm-memory/ directory (recursive)
PM_MEM_DIR="$SRC/agents/maltyweb-pm-memory"
PM_MEM_DST="$BRAIN/agents/maltyweb-pm-memory"
if [ -d "$PM_MEM_DIR" ]; then
    rm -rf "$PM_MEM_DST"
    cp -a "$PM_MEM_DIR" "$PM_MEM_DST"
    n_files=$(find "$PM_MEM_DST" -type f | wc -l)
    size=$(du -sh "$PM_MEM_DST" | cut -f1)
    echo "  [OK] maltyweb-pm-memory/  ($n_files files, $size)"
else
    echo "  WARN: maltyweb-pm-memory/ directory not found at $PM_MEM_DIR — skipping"
fi

# ── Summary ───────────────────────────────────────────────────────────────────
echo ""
n_skills=$(ls "$BRAIN/skills/" 2>/dev/null | wc -l)
n_agents=$(find "$BRAIN/agents" -maxdepth 1 -type f | wc -l)
total_size=$(du -sh "$BRAIN" | cut -f1)

echo "=== Done ==="
echo "  Skills copied  : $n_skills"
echo "  Agent files    : $n_agents  (+ maltyweb-pm-memory/ dir)"
echo "  Total size     : $total_size"
echo ""
echo "Next steps: review the snapshot, then commit + push."
echo "Tell Louis: git pull + re-run bootstrap.sh inside the maltyweb clone."
