#!/usr/bin/env bash
# bootstrap.sh — run by the NEW developer (Louis) on HIS OWN machine, from inside
# his local maltyweb clone.
#
# Installs skills + agent DEFINITIONS into ~/.claude (with /home/kluk paths
# rewritten to his home), and SYMLINKS the PM memory to the repo copy so his PM
# reads/writes the SAME git-synced knowledge base as the keeper. The PM memory is
# NOT copied — it lives in the repo and syncs via `git pull` / `git push`.
#
# Assumptions:
#   - maltyweb and maltytask are SIBLING clones under one parent directory
#     (e.g. ~/projects/maltyweb and ~/projects/maltytask).
#   - Override the projects parent with: MALTYTASK_PARENT=/path ./bootstrap.sh
#
# Safe to re-run after a git pull that touched claude-brain/.
set -euo pipefail

BRAIN="$(cd "$(dirname "$0")" && pwd)"
DEST="$HOME/.claude"
REPO_ROOT="$(cd "$BRAIN/.." && pwd)"

if [ -n "${MALTYTASK_PARENT:-}" ]; then
    PROJECTS_PARENT="$MALTYTASK_PARENT"
else
    PROJECTS_PARENT="$(cd "$REPO_ROOT/.." && pwd)"
fi

echo "=== claude-brain bootstrap ==="
echo "  Brain source    : $BRAIN"
echo "  Install target  : $DEST"
echo "  Projects parent : $PROJECTS_PARENT  (maltyweb + maltytask live here)"
echo "  ASSUMPTION: maltytask is at $PROJECTS_PARENT/maltytask"
echo "  (wrong? re-run with MALTYTASK_PARENT=/correct/path ./bootstrap.sh)"
echo ""

mkdir -p "$DEST/skills" "$DEST/agents"

# ── Skills ────────────────────────────────────────────────────────────────────
echo "Installing skills..."
if [ -d "$BRAIN/skills" ] && [ -n "$(ls -A "$BRAIN/skills" 2>/dev/null)" ]; then
    cp -a "$BRAIN/skills/." "$DEST/skills/"
    echo "  Copied $(ls "$BRAIN/skills" | wc -l) skill(s)."
else
    echo "  WARN: $BRAIN/skills empty — run publish.sh on the keeper machine first."
fi

# ── Agent DEFINITIONS (real files only — never the memory) ───────────────────
echo "Installing agent definitions..."
for f in maltyweb-pm.md maltyweb-tour-steward.md; do
    if [ -f "$BRAIN/agents/$f" ]; then cp -a "$BRAIN/agents/$f" "$DEST/agents/$f"; echo "  [OK] $f"; fi
done

# ── PM MEMORY: SYMLINK to the repo copy (single git-synced brain) ────────────
# The PM knowledge base is canonical in the repo. Point ~/.claude at it via
# symlink so your PM reads AND writes the shared, git-synced memory.
echo "Symlinking PM memory to the git-synced repo copy..."
rm -rf "$DEST/agents/maltyweb-pm-memory" "$DEST/agents/maltyweb-pm-memory.md"
ln -s "$BRAIN/agents/maltyweb-pm-memory.md" "$DEST/agents/maltyweb-pm-memory.md"
ln -s "$BRAIN/agents/maltyweb-pm-memory"    "$DEST/agents/maltyweb-pm-memory"
echo "  [OK] ~/.claude/agents/maltyweb-pm-memory{.md,/} -> $BRAIN/agents/maltyweb-pm-memory*"

# ── Path rewrite — installed DEFS + skills only ──────────────────────────────
# find (no -L) does NOT follow the memory symlinks, so the repo's shared memory
# is never rewritten with this machine's paths. Only real installed files change.
echo "Rewriting /home/kluk paths in installed files..."
mapfile -t FILES < <(
    find "$DEST/skills" "$DEST/agents" -type f \
        \( -name "*.md" -o -name "*.txt" -o -name "*.json" -o -name "*.sh" -o -name "*.php" -o -name "*.ts" -o -name "*.js" \) \
        2>/dev/null
)
rewritten=0
for f in "${FILES[@]}"; do
    if grep -q "/home/kluk" "$f" 2>/dev/null; then
        # ORDER MATTERS: longest prefix first.
        sed -i \
            -e "s|/home/kluk/projects|${PROJECTS_PARENT}|g" \
            -e "s|/home/kluk|${HOME}|g" \
            "$f"
        rewritten=$((rewritten + 1))
    fi
done
echo "  Rewrote paths in $rewritten file(s)."

# leftover check
leftover=$(grep -rl "/home/kluk" "$DEST/skills" "$DEST/agents" 2>/dev/null || true)
if [ -n "$leftover" ]; then
    echo "  WARN: residual /home/kluk references remain — review:"; echo "$leftover" | sed 's/^/    /'
else
    echo "  OK — no /home/kluk references remain."
fi

# ── Auto-sync hook: push PM-memory changes on every git commit/push ───────────
# Keeps the shared brain in sync without anyone remembering to commit it.
# Idempotent; merges into ~/.claude/settings.json. Needs python3 (else: manual, see README).
echo ""
echo "Installing PM-memory auto-sync hook (~/.claude/settings.json)..."
if command -v python3 >/dev/null 2>&1; then
    BRAIN="$BRAIN" python3 - <<'PY'
import json, os
brain = os.environ["BRAIN"]
p = os.path.expanduser("~/.claude/settings.json")
cmd = "input=$(cat); printf '%s' \"$input\" | grep -qE 'git (commit|push)' && " + brain + "/pm-sync.sh; true"
d = {}
if os.path.exists(p):
    try:
        with open(p) as f: d = json.load(f)
    except Exception:
        d = {}
pt = d.setdefault("hooks", {}).setdefault("PostToolUse", [])
present = any("pm-sync.sh" in h.get("command", "") for blk in pt for h in blk.get("hooks", []))
if present:
    print("  hook already present — skipped")
else:
    pt.append({"matcher": "Bash", "hooks": [{"type": "command", "command": cmd}]})
    os.makedirs(os.path.dirname(p), exist_ok=True)
    with open(p, "w") as f: json.dump(d, f, indent=2)
    print("  hook added -> runs pm-sync.sh after your git commit/push")
PY
else
    echo "  WARN: python3 not found. Add the hook manually (see claude-brain/README.md)."
fi

echo ""
echo "=== Done — restart Claude Code to load the skills + agents. ==="
echo ""
echo "Your PM shares ONE git-synced knowledge base with the team:"
echo "  * git pull BEFORE consulting PM (get the latest build-state)"
echo "  * after PM updates its memory, the changed files appear under"
echo "    claude-brain/agents/maltyweb-pm-memory* — commit + push them so the"
echo "    keeper's PM stays in sync."
echo ""
echo "NOTE: the PM def references the primary dev's personal auto-recall memory"
echo "(~/.claude/projects/-home-...-maltytask/memory/) which won't exist on your"
echo "machine — expected and harmless; PM treats those as optional."
