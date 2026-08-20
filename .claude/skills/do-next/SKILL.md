---
name: do-next
description: Implement the next build slice for the RFC-1 / Plan-0002 execution. Reads docs/architecture/build-manifest.md, picks the first unmerged row, and implements it under TDD on a slice/<slug> branch. Invoke with /do-next.
---

# do-next — implement the next build slice

Execute "Mode A" of `docs/architecture/build-procedure.md`. **Do not guess; halt and
ask on any ambiguity.** The whole point is that a cold session picks the correct next
slice from durable state, not from memory.

## 0. Read the goal first

Read **"The goal every row serves"** in `build-manifest.md` before anything else.
Slices exist to make it impossible for two features to disagree about the same
symbol. A slice's acceptance criteria are how it serves that goal, not a substitute
for it — and work the goal does not call for is not made in-scope by being nearby,
noticed in passing, or easy.

## 1. Preconditions (halt and report if any fail)

- `git status`: the **tracked** working tree must be clean. Untracked scratch files
  (e.g. `notes.txt`, coverage output) are fine. If tracked files are modified, stop.
- Switch to `main` and sync: `git fetch origin`; if `main` is behind, fast-forward;
  if diverged, stop and report.
- Verify the base is green: run `composer test`. If red, **stop** — do not build on
  a red base.

## 2. Find the next slice

- Read `docs/architecture/build-manifest.md`. The **Remaining work** section is an
  ordered list. Each row has a **slug** in bold (e.g. `**enforcement-rules**`); the
  branch is `slice/<slug>`.
- Walk the list top to bottom. For each row, check **GitHub PR merge state**:
  - `done` — a merged PR exists: `gh pr list --state merged --head slice/<slug> --json number` returns one.
  - `in-flight` — an open PR exists: `gh pr list --state open --head slice/<slug>`.
  - `todo` — neither.
- The **next slice** is the first `todo` row.
- **Preflight sanity check:** every row above the next slice should be `done`. If any
  is `in-flight` or `todo`, report the state drift and halt — the list order is the
  dependency order.

Deriving from PR merge state (not ancestry) is what keeps this correct under the
project's **Squash and Merge**: a squash rewrites the branch into one new commit on
`main`, so an ancestry check would report squashed slices as `todo` forever. Check
`done` before `in-flight` so a squash-deleted branch is read as done, not unstarted.

## 3. Check for phantoms

A row states its work in prose, and prose goes stale: a row can claim a mechanism that
already exists, or a removal already made. Selecting one costs a whole session before
anyone notices, which has happened.

Most rows are baseline drains, and the baseline is machine-readable, so check before
offering: if the row names files or entries it drains, confirm those entries are still
in `phpstan-baseline.neon` / `deptrac.baseline.yaml`. If the row drains no baseline
entry, spot-check its central claim against the code — one `grep` is enough; the
failure mode is a row asserting that something is absent when it is present.

**Baseline rule:** `enforcement-rules` is the only slice that may grow a baseline (the
human overrides CI). Every other slice must leave baselines flat or shrink them —
widening an enforcement rule's allowlist to avoid growth is the same failure.

A row whose claim no longer holds is a **phantom**. Report it as such, say what appears
to have discharged it, and ask whether to remove it from the list and continue to the
next row.

## 4. Safeguards (halt and report; do NOT proceed) if

- All slices are done — the manifest is complete.
- A row above the next slice is not merged — state drift (see preflight above).

## 5. Explain the slice

Read the slice's plan step in `docs/architecture/0002-execution-plan.md` for its
acceptance criteria, and the RFC sections it cites in `0001-foundational-architecture.md`.
Then describe the work in plain english and **wait** for approval, clarification, or
modification before writing anything.

Lead that description with the slice's answer to the goal test: what two features could
disagree about without this slice, or which scheduled feature it unblocks. If you cannot
write that sentence from the plan, **stop and ask** — a slice whose purpose you cannot
state is one you will over- or under-build.

## 6. Implement the slice

- Create `slice/<slug>` off `main`.
- TDD:
  - Behavior-preserving slice → add/extend the Step P parity fixtures **first**.
  - Seam-introducing slice → add its §8.1 enforcement rule in this slice.
  - Write failing tests, then implement to green.
- Keep commits small and logical (project rule). Run `composer test` to green.
- Build what the acceptance criteria require and nothing beyond it. A defect,
  duplication, or rough edge noticed in passing is **reported, not solved** —
  duplication or divergence earns a new manifest row; a defect gets a GitHub issue
  plus a line in the final report saying whether the next slice can proceed while it
  stays open; generic tidiness gets one line in the PR body and neither a row nor a diff.
- Comments earn their place. Name the non-obvious fact each one carries that the code
  does not, or leave it out; length tracks the subtlety of the code, not its size.
  Reviewers raise violations as findings, so writing them costs a round.
- If you hit a fundamental design question the plan does not answer, **STOP and ask**
  — do not invent an interpretation.

## 7. Open the PR and report

- PR title carries no issue number; the body opens with what two features could have
  disagreed about without this change (or what it unblocks), then cites the slice slug,
  plan step, and RFC section(s), and lists the acceptance criteria as a checklist.
- List manifest `Closes` candidates from the Issue wiring section as "Candidate closes
  (pending review verification): #n" — do **not** wire `Closes #n` here; that is the
  reviewer's job after reading the issue body.
- Report the PR URL and name the **next** slice (the row below this one) so a follow-up
  `/do-next` is predictable.
