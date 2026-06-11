---
name: memory-hygiene
description: >-
  Compact and reorganise bloated Claude memory systems WITHOUT breaking recall.
  Use whenever a memory index has grown too big — a project `memory/MEMORY.md`
  over its line budget, an agent memory file that errors on Read (>256KB), a
  giant topic file, or the user says things like "optimise memory", "compact
  memory", "the memory is bloated", "create directories and reference in the
  memory", "the index is too long", or "trim the memory". Trigger this even if
  the user only describes the symptom (slow/expensive context, an index that
  won't load) rather than naming "memory hygiene" explicitly. The single most
  important thing this skill protects: the difference between harness
  auto-recall memory (must stay FLAT) and agent memory (subdirectories are
  safe). Get that wrong and recalled memories silently vanish.
---

# Memory Hygiene

Memory systems rot the same way every time: detail that belongs in a topic file
accretes in the index, the index outgrows its budget, and either it stops loading
(agent memory >256KB errors on Read) or it burns context every session (an
auto-recall `MEMORY.md` that's hundreds of lines). The fix is always the same
shape — **move detail out, leave a one-line pointer, verify nothing was lost** —
but the *mechanics differ by memory type*, and getting the type wrong is how you
silently break recall.

## First: identify which memory type you're compacting

This is the load-bearing decision. The two types look similar (an index file +
topic files) but are loaded by completely different mechanisms, which dictates
whether directories are safe.

### Type A — Harness auto-recall memory  → **MUST STAY FLAT**
- **Shape:** `<project>/memory/MEMORY.md` + topic files (`*.md`) sitting flat in
  the same directory, each with YAML frontmatter (`name`, `description`, `metadata`).
- **How it loads:** the harness auto-loads `MEMORY.md` into context every session,
  and surfaces individual topic files by **semantic match on their `description`**,
  injecting them inside `<system-reminder>` blocks. You never Read them by path.
- **Why flat is mandatory:** the recall mechanism has **no proven support for
  subdirectories** — across all projects on the machine, zero auto-memories use
  subdirs and every `MEMORY.md` pointer is a bare filename. Moving a topic file
  into `subdir/` risks making it invisible to recall. **Never create directories
  in Type A memory.** Compact it by trimming the index only; topic files stay put.

### Type B — Agent memory  → **directories are safe**
- **Shape:** `<agent>-memory.md` (the always-loaded index) + a `<agent>-memory/`
  directory of topic files. Example: `~/.claude/agents/maltyweb-pm-memory.md` +
  `~/.claude/agents/maltyweb-pm-memory/`.
- **How it loads:** the agent reads its own index first, then Reads/Greps topic
  files **by explicit path** that it controls. Recall is not semantic injection —
  it's the agent navigating its own filesystem.
- **Why directories are safe:** because reads are explicit-path, you can nest
  freely. A 165KB `build-history.md` can become a `build-history/` directory of
  per-arc files plus a small index, and the agent just follows the pointers.

If you're not sure which type you're looking at: a file named `MEMORY.md` whose
siblings are flat `.md` files with frontmatter = Type A. A file named
`<something>-memory.md` with a sibling `<something>-memory/` directory = Type B.

## Budgets (when to act)

- **Type A `MEMORY.md`:** aim **< ~190 lines and < ~30KB**. One line per memory,
  ≤ ~200 chars. If it's longer, detail has leaked into the index — push it down.
- **Type B index (`<agent>-memory.md`):** **hard ceiling 256KB** (above this it
  errors on a full Read and the agent has to grep/offset blind). Target **well
  under 100KB** so it loads fast and cheap every single consult.
- **Type B topic files:** split when a single file exceeds **~80KB** — at that
  size it's doing the job of a directory.

## The compaction procedure

Same four moves regardless of type; only step 3's "where" differs.

### 1. Measure
```bash
# Type A
wc -l <project>/memory/MEMORY.md
# Type B — index size + largest topic files
ls -la <agent>-memory.md | awk '{print $5}'
ls -lS <agent>-memory/ | head
```
Note what's over budget: the index, specific topic files, or both.

### 2. Identify what doesn't belong in the index
The index is a **routing table**, not a store. Anything that is more than a
one-line pointer is a candidate to move out:
- multi-line entries, narratives, worked examples, command transcripts;
- "ACTIVE ARC" banners that carry their full recap inline (keep a one-line
  pointer + the topic file holds the recap);
- duplicated facts that already live in a topic file.

### 3. Move it out (never delete — relocate)
- **Type A:** append the detail to the **existing flat topic file** it belongs to
  (or create a new flat topic file *with frontmatter* if none exists), then
  replace the index entry with a one-line pointer: `- [Title](file.md) — hook`.
  **Do not create subdirectories.**
- **Type B:** move the detail into the topic file, leaving a one-line pointer in
  the index. If a **topic file itself** is oversized, create a directory
  `<topic>/`, split it into per-subtopic files, and turn the old file (or a new
  `<topic>/README.md`) into a small index of pointers into that directory. Update
  the parent index's pointer to aim at the new directory index.

### 4. Verify (the step that catches silent loss)
- The index now Reads cleanly and is under budget (`wc -l` / size check).
- **No content lost:** compaction MOVES, it never deletes. Confirm by grepping a
  few distinctive tokens from the moved content and checking they still resolve
  *somewhere* (`grep -rl "<token>" <memory-root>`). If a fact is genuinely
  obsolete or wrong, removing it is a **separate, explicit prune decision** you
  flag to the user — never a silent casualty of compaction.
- **Every topic is reachable:** each topic file/dir has a pointer from the index
  (Type B) or a `description` good enough to recall on (Type A).
- **Links intact:** preserve `[[wikilinks]]` and frontmatter.

`scripts/audit.sh <memory-root>` does the measure step and flags over-budget
files for either type — run it first and again at the end to confirm you came in
under budget.

## Recall-safety rules (the don't-break list)

1. **Never put Type A topic files in subdirectories.** This is the one that
   silently destroys recall. Directories are a Type-B-only tool.
2. **Never delete during compaction.** Relocate. Obsolete content is a separate,
   surfaced decision.
3. **The index must stay self-sufficient as a routing table** — after
   compaction, every topic must still be findable (a pointer in B, a strong
   `description` in A).
4. **Preserve frontmatter and `[[links]]`** when moving Type A content.
5. **Don't churn working memory for aesthetics.** Compact what's over budget;
   leave lean files alone. The goal is loadability and cost, not uniformity.
6. **Agent owns its own memory.** When compacting a Type B store that belongs to
   a subagent (e.g. a PM agent), prefer delegating the reorg to that agent — it
   knows what's load-bearing vs. archival. Drive it; don't blindly restructure
   another agent's store from outside.

Explicit ownership-routing in skill descriptions reduces trigger collisions — see `skill-creator/references/tdd-for-skills.md`.

## Worked shape

**Type A — index entry too fat:**
```
Before (in MEMORY.md):
  > 🟡 ACTIVE ARC — Foo. Three-paragraph recap of everything that happened,
  > the commits, the gotchas, the next steps, inline...   ← burns context every session

After (in MEMORY.md):
  > 🟡 ACTIVE ARC — Foo (paused). One-line status. → [[project-foo-arc]]
  (the three paragraphs now live in project-foo-arc.md, a flat sibling)
```

**Type B — giant topic file → directory:**
```
Before:  maltyweb-pm-memory/build-history.md          (165KB, unwieldy)
After:   maltyweb-pm-memory/build-history/README.md    (small pointer index)
         maltyweb-pm-memory/build-history/2026-05-packaging.md
         maltyweb-pm-memory/build-history/2026-06-auth.md
         ...
  and the main index's pointer aims at build-history/README.md
```
