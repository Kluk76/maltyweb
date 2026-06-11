# claude-brain — Team Claude Code Distribution Kit

## What this is

The team's Claude Code **skills + agents**, distributed via the maltyweb git repo
so every developer's Claude is in lockstep. The PM knowledge base lives here
**canonically** and is shared live between developers through git.

Contents:

- **`skills/`** — Seven bespoke skills: `coder`, `sql`, `ui`, `webapp-testing`,
  `memory-hygiene`, `skill-vetting`, `xlsx`. The team's coding discipline,
  anti-pattern catalog, MySQL conventions, UI patterns, verification recipes.
- **`agents/`** — the `maltyweb-pm` + `maltyweb-tour-steward` agent definitions,
  **and the canonical PM knowledge base** (`maltyweb-pm-memory.md` +
  `maltyweb-pm-memory/`): the SQL build schema, the Le Zeppelin derivation tree,
  the UI build sequence, the project roadmap, and the live build-state.

Skills deliberately **not** included: `skill-creator` (PM-authoring tooling only)
and `parser-coder` (invoice-parser scope; outside the sales-dev remit).

## The model — one git-synced brain (NOT a one-way snapshot)

The **PM knowledge base is canonical in this repo** and is edited *in place*. On
every developer's machine, `~/.claude/agents/maltyweb-pm-memory{.md,/}` is a
**symlink** to this folder's copy — so each developer's PM reads AND writes the
same files, and **`git pull` / `git push` is the sync layer**. When one PM records
build-state, the other sees it on the next pull. PM genuinely follows everyone's work.

- **Skills + agent definitions** are *copied* into `~/.claude` by `bootstrap.sh`
  (they change rarely). Refresh them with `publish.sh` + commit when they change.
- **PM memory** is *symlinked*, never copied — it is the single shared brain.

## Every developer's discipline (both of you)

- **`git pull` BEFORE consulting PM** — so you load the latest build-state.
- **After PM updates its memory**, the changed files show up under
  `claude-brain/agents/maltyweb-pm-memory*`. **Commit + push them** so the other
  PM stays in sync:
  ```bash
  git add claude-brain/agents/maltyweb-pm-memory   # the changed memory files
  git commit -m "chore(pm-memory): <what changed>"
  git push
  ```
- Concurrent edits to the same memory file can merge-conflict — topic files keep
  most changes isolated; resolve the index (`maltyweb-pm-memory.md`) by hand if needed.

## Auto-sync hook — `pm-sync.sh` (so nobody has to remember)

`bootstrap.sh` installs a Claude Code `PostToolUse` hook into your
`~/.claude/settings.json` that runs `claude-brain/pm-sync.sh` after every
`git commit` / `git push`. The script commits **only** the PM-memory paths and
best-effort pushes — it's a no-op when memory is unchanged, never touches your
code, and always exits clean. Net effect: PM memory rides out automatically
whenever you do git. Run it manually any time too: `./claude-brain/pm-sync.sh`.

The hook only auto-**pushes**; `git pull` before consulting PM is still on you
(that's how you load the other dev's updates). The keeper installs the same hook
in their own user settings.

## Refreshing skills / agent defs (when they change) — `publish.sh`

`publish.sh` copies the **skills + agent definitions** from the keeper's
`~/.claude` into this folder. It does **NOT** touch the PM memory (that's
git-synced, edited in place). Run it when a skill or an agent definition changes:

```bash
./claude-brain/publish.sh
git add claude-brain/skills claude-brain/agents/*.md
git commit -m "chore(claude-brain): refresh skills/defs"
git push
```

## New-dev setup (once) — `bootstrap.sh`

```bash
git pull
./claude-brain/bootstrap.sh       # installs skills + agent defs into ~/.claude,
                                  # and SYMLINKS the PM memory to this repo copy
# Restart Claude Code
```

Re-run `bootstrap.sh` after any pull that touched `claude-brain/` (idempotent).

**Assumption:** `maltyweb` and `maltytask` are sibling clones under one parent
(e.g. `~/projects/maltyweb` and `~/projects/maltytask`). If not, override:

```bash
MALTYTASK_PARENT=/path/to/your/projects ./claude-brain/bootstrap.sh
```

## Why it's not under `.claude/`

A repo `.claude/` would auto-load and shadow/fork each developer's own user-level
agents + skills. `claude-brain/` keeps install **explicit**: `bootstrap.sh`
installs into your own `~/.claude/` (skills/defs copied, memory symlinked).

## Scope contract

The sales-dev's Claude works within `docs/ONBOARDING-sales.md` (the canonical
scope contract). The `maltyweb-pm` agent is the live source of truth for what to
build and how — consult it before any non-trivial build.
