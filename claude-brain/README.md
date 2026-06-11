# claude-brain — Team Claude Code Distribution Kit

## What this is

A **published snapshot** of the team's Claude Code skills and agents, distributed via the maltyweb git repo so the second developer (Louis) can bootstrap his own `~/.claude/` with the same tools Kouros uses.

Contents:

- **`skills/`** — Seven bespoke skills: `coder`, `sql`, `ui`, `webapp-testing`, `memory-hygiene`, `skill-vetting`, `xlsx`. These encode the team's coding discipline, anti-pattern catalog, MySQL conventions, UI patterns, and verification recipes.
- **`agents/`** — The `maltyweb-pm` and `maltyweb-tour-steward` agents, plus the PM's full knowledge base (`maltyweb-pm-memory.md` + `maltyweb-pm-memory/`). The PM agent is the keeper of the canonical SQL build schema, the Le Zeppelin derivation tree, the UI build sequence, and the project roadmap.

Skills deliberately **not included** here:
- `skill-creator` — PM-authoring tooling; only Kouros maintains the skill library.
- `parser-coder` — invoice parser scope; outside the sales-dev remit.

## The model

**Kouros is the single live keeper.** His `~/.claude/` is the source of truth. This folder is a published snapshot — it is NOT a live two-way sync.

Build state from Louis flows back **through** Kouros: Louis tells Kouros what happened in a session → Kouros records it in PM memory → Kouros re-publishes. This is intentional: PM's knowledge base is carefully curated, not crowd-sourced.

## Primary dev workflow (Kouros)

After a build session advances PM memory (a migration lands, a page ships, a convention is decided):

```bash
./claude-brain/publish.sh      # snapshots ~/.claude skills + agents here
# Review the diff (git diff claude-brain/)
git add claude-brain/
git commit -m "chore(claude-brain): refresh snapshot YYYY-MM-DD"
git push
```

## New dev workflow (Louis)

```bash
git pull                          # always pull before starting a build
./claude-brain/bootstrap.sh       # installs into your ~/.claude/
# Restart Claude Code
```

Re-run `bootstrap.sh` after any pull that touched `claude-brain/`. It is idempotent.

**Assumption:** `maltyweb` and `maltytask` are sibling clones under the same parent directory (e.g. `/home/louis/projects/maltyweb` and `/home/louis/projects/maltytask`). If your layout differs, override:

```bash
MALTYTASK_PARENT=/path/to/your/projects ./claude-brain/bootstrap.sh
```

## Why it's not under `.claude/`

A directory named `.claude/` inside the repo would be loaded automatically by Claude Code and would shadow (or fork) each developer's own user-level agents and skills. This folder is named `claude-brain/` so the install is **explicit**: Louis runs `bootstrap.sh` once and the skills land in his own `~/.claude/`, where they belong. Neither developer's live `~/.claude/` is touched without intention.

## Scope contract

The new dev's Claude works within the scope defined in `docs/ONBOARDING-sales.md` (the canonical scope contract for the sales-dev role). The `maltyweb-pm` agent is the live source of truth for what to build and how — always consult it before starting any non-trivial build.

## Snapshot lag

The PM knowledge base can lag Kouros's live session by hours or days. Always `git pull` before a build to load the latest snapshot. If you're unsure whether the PM memory is current, ask Kouros to re-publish.
