#!/usr/bin/env bash
# bootstrap.sh — run by the NEW developer (Louis) on HIS OWN machine,
# from inside his local maltyweb clone.
#
# Installs skills + agents into ~/.claude, then rewrites all /home/kluk paths
# to point at the new dev's home + projects directory.
#
# Assumptions:
#   - maltyweb and maltytask are SIBLING clones under the same parent directory.
#     (e.g. /home/louis/projects/maltyweb  and  /home/louis/projects/maltytask)
#   - Override the projects parent via: MALTYTASK_PARENT=/custom/path ./bootstrap.sh
#
# Safe to re-run after a git pull that touched claude-brain/.
set -euo pipefail

BRAIN="$(cd "$(dirname "$0")" && pwd)"
DEST="$HOME/.claude"
REPO_ROOT="$(cd "$BRAIN/.." && pwd)"

# Projects parent — sibling-clone assumption; override with MALTYTASK_PARENT env var.
if [ -n "${MALTYTASK_PARENT:-}" ]; then
    PROJECTS_PARENT="$MALTYTASK_PARENT"
else
    PROJECTS_PARENT="$(cd "$REPO_ROOT/.." && pwd)"
fi

echo "=== claude-brain bootstrap ==="
echo "  Brain source      : $BRAIN"
echo "  Install target    : $DEST"
echo "  Repo root         : $REPO_ROOT"
echo "  Projects parent   : $PROJECTS_PARENT  (maltyweb + maltytask live here)"
echo ""
echo "  ASSUMPTION: maltytask is at $PROJECTS_PARENT/maltytask"
echo "  If that is wrong, re-run with: MALTYTASK_PARENT=/correct/path ./bootstrap.sh"
echo ""

# ── Create target dirs ────────────────────────────────────────────────────────
mkdir -p "$DEST/skills" "$DEST/agents"

# ── Copy skills ───────────────────────────────────────────────────────────────
echo "Installing skills..."
if [ -d "$BRAIN/skills" ] && [ -n "$(ls -A "$BRAIN/skills" 2>/dev/null)" ]; then
    cp -a "$BRAIN/skills/." "$DEST/skills/"
    n_skills=$(ls "$BRAIN/skills/" | wc -l)
    echo "  Copied $n_skills skill(s) -> $DEST/skills/"
else
    echo "  WARN: $BRAIN/skills/ is empty or missing — nothing to copy. Run publish.sh first."
    n_skills=0
fi

# ── Copy agents ───────────────────────────────────────────────────────────────
echo "Installing agents..."
if [ -d "$BRAIN/agents" ] && [ -n "$(ls -A "$BRAIN/agents" 2>/dev/null)" ]; then
    cp -a "$BRAIN/agents/." "$DEST/agents/"
    n_agents=$(find "$DEST/agents" -maxdepth 1 -type f -name "*.md" | wc -l)
    echo "  Copied agents -> $DEST/agents/  ($n_agents top-level .md files)"
else
    echo "  WARN: $BRAIN/agents/ is empty or missing — nothing to copy. Run publish.sh first."
    n_agents=0
fi

# ── Path rewrite (on the COPIES in $DEST only — never the repo) ───────────────
echo ""
echo "Rewriting /home/kluk paths in installed files..."

# Collect all text files installed into $DEST/skills and $DEST/agents
mapfile -t FILES_TO_REWRITE < <(
    find "$DEST/skills" "$DEST/agents" -type f \
        \( -name "*.md" -o -name "*.txt" -o -name "*.json" -o -name "*.sh" -o -name "*.php" -o -name "*.ts" -o -name "*.js" \) \
        2>/dev/null
)

rewritten=0
for f in "${FILES_TO_REWRITE[@]}"; do
    if grep -q "/home/kluk" "$f" 2>/dev/null; then
        # ORDER MATTERS: longest-prefix first to prevent double-substitution.
        # 1. Repo locations: /home/kluk/projects → $PROJECTS_PARENT
        # 2. Everything else: /home/kluk → $HOME
        # Use | as sed delimiter (HOME/PROJECTS_PARENT won't contain |).
        sed -i \
            -e "s|/home/kluk/projects|${PROJECTS_PARENT}|g" \
            -e "s|/home/kluk|${HOME}|g" \
            "$f"
        rewritten=$((rewritten + 1))
    fi
done
echo "  Rewrote paths in $rewritten file(s)."

# ── Verify: flag any remaining /home/kluk occurrences ────────────────────────
echo ""
echo "Checking for leftover /home/kluk references..."
leftover_files=()
for f in "${FILES_TO_REWRITE[@]}"; do
    if grep -q "/home/kluk" "$f" 2>/dev/null; then
        leftover_files+=("$f")
    fi
done

if [ ${#leftover_files[@]} -gt 0 ]; then
    echo "  WARN: the following installed files still contain /home/kluk — manual review needed:"
    for f in "${leftover_files[@]}"; do
        echo "    $f"
        grep -n "/home/kluk" "$f" | head -3 | sed 's/^/      /'
    done
else
    echo "  OK — no /home/kluk references remain in installed files."
fi

# ── Summary ───────────────────────────────────────────────────────────────────
echo ""
echo "=== Installation complete ==="
echo "  Skills installed : $n_skills"
echo "  Agents installed : $n_agents  (+ maltyweb-pm-memory/ dir)"
echo ""
echo "  Restart Claude Code to pick up the new skills and agents."
echo ""
echo "  NOTE: The maltyweb-pm agent's knowledge base contains references to the"
echo "  primary dev's personal auto-recall memory (a path like:"
echo "  ~/.claude/projects/-home-kluk-projects-maltytask/memory/)"
echo "  That path will not exist on your machine — this is expected and harmless."
echo "  The PM agent treats those memory files as optional context."
echo ""
echo "  IMPORTANT: This snapshot can lag the primary dev's live PM knowledge."
echo "  Always 'git pull' before starting a build to get the latest snapshot."
