#!/usr/bin/env bash
# publish.sh — sync SKILLS + AGENT DEFINITIONS from the keeper's ~/.claude into
# this repo folder. Run by the keeper (Kouros) when a skill or an agent
# definition changes. READ-ONLY on ~/.claude (copy-out only).
#
# PM MEMORY is deliberately NOT handled here. The PM knowledge base lives
# canonically IN THIS REPO (claude-brain/agents/maltyweb-pm-memory{.md,/}) and is
# edited in place — the keeper's ~/.claude path AND the new dev's are SYMLINKS to
# it. It syncs between developers via `git pull` / `git push`, never via this
# script. See README.md. (Copying it here would copy a symlink onto its own
# target — hence the explicit exclusion + symlink guard below.)
set -euo pipefail

BRAIN="$(cd "$(dirname "$0")" && pwd)"
SRC="$HOME/.claude"

# Skills (excluded: skill-creator = PM-authoring only; parser-coder = out of sales
# scope; *-workspace = scratch).
SKILLS_TO_COPY=(coder sql ui webapp-testing memory-hygiene skill-vetting xlsx)

# Agent DEFINITIONS only — NEVER the memory (see header).
AGENT_DEFS=(maltyweb-pm.md maltyweb-tour-steward.md)

echo "=== claude-brain publish (skills + agent DEFS only; memory syncs via git) ==="
echo "  Source : $SRC"
echo "  Target : $BRAIN"
echo ""

mkdir -p "$BRAIN/skills" "$BRAIN/agents"

echo "Skills:"
for skill in "${SKILLS_TO_COPY[@]}"; do
    src="$SRC/skills/$skill"; dst="$BRAIN/skills/$skill"
    if [ ! -d "$src" ]; then echo "  WARN: skill '$skill' not found at $src — skipping"; continue; fi
    rm -rf "$dst"; cp -a "$src" "$dst"
    echo "  [OK] $skill  ($(du -sh "$dst" | cut -f1))"
done

echo "Agent definitions:"
for f in "${AGENT_DEFS[@]}"; do
    src="$SRC/agents/$f"; dst="$BRAIN/agents/$f"
    if [ ! -f "$src" ]; then echo "  WARN: agent def '$f' not found at $src — skipping"; continue; fi
    if [ -L "$src" ]; then echo "  WARN: '$f' is a symlink — skipping (defs must be real files)"; continue; fi
    cp -a "$src" "$dst"
    echo "  [OK] $f"
done

echo ""
echo "Done. PM MEMORY was NOT touched (it lives in this repo and syncs via git)."
echo "Next: review, commit, push. Louis then: git pull + ./claude-brain/bootstrap.sh"
