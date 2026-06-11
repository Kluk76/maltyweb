#!/usr/bin/env bash
# pm-sync.sh — sweep PM-memory changes into git + push, so the shared PM brain
# stays in sync without anyone remembering to commit it.
#
# Wired as a Claude Code PostToolUse hook on git commit / git push (see
# settings.json). Run manually any time too: ./claude-brain/pm-sync.sh
#
# SAFE BY DESIGN:
#   - commits ONLY the maltyweb-pm-memory paths (never your code changes)
#   - no-op if the memory hasn't changed
#   - best-effort push; if the remote moved on (non-ff), the memory commit just
#     waits locally for your next pull+push — it is never lost
#   - NEVER rebases/merges (so it can't leave a half-resolved conflict)
#   - always exits 0 (a hook must not break your workflow)
set +e

REPO="$(cd "$(dirname "$0")/.." && pwd)"   # maltyweb repo root
cd "$REPO" 2>/dev/null || exit 0

MEM="claude-brain/agents/maltyweb-pm-memory"
MEM_MD="claude-brain/agents/maltyweb-pm-memory.md"

# Anything to sync? (unstaged OR staged changes under the memory tree)
if git diff --quiet -- "$MEM" "$MEM_MD" 2>/dev/null \
   && git diff --cached --quiet -- "$MEM" "$MEM_MD" 2>/dev/null; then
    exit 0   # PM memory unchanged — nothing to do
fi

git add -- "$MEM" "$MEM_MD" 2>/dev/null
git commit -q -m "chore(pm-memory): auto-sync" -- "$MEM" "$MEM_MD" 2>/dev/null \
    && echo "[pm-sync] committed PM-memory changes"

# Best-effort push. If it fails (e.g. remote ahead), the commit stays local and
# rides out on your next manual pull+push. We do NOT auto-pull/rebase here.
if git push -q 2>/dev/null; then
    echo "[pm-sync] pushed PM memory"
else
    echo "[pm-sync] PM memory committed locally; push deferred (pull+push when ready)"
fi
exit 0
