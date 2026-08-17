---
name: do-next
description: Report which build slices are startable for the RFC-1 / Plan-0002 execution, then implement the one chosen. Reads docs/architecture/build-manifest.md, derives status from git/PR merge state (never a status field), enforces preconditions, and implements under TDD on a slice/<id> branch. Invoke with /do-next.
---

# do-next — report what is startable, then build the chosen slice

Execute "Mode A" of `docs/architecture/build-procedure.md`. **Do not guess; halt and
ask on any ambiguity.** The point is that a cold session establishes what is *legal to
start* from durable state rather than from memory — and then the human picks.

**This skill does not choose the slice.** Manifest row order records when a row was
filed, not what matters next, so picking the first startable row is an arbitrary choice
dressed as a computed one. Report the set; let the human choose from it.

## 0. Read the goal first

Read **"The goal every slice serves"** in `build-procedure.md` before anything else.
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

## 2. Compute the startable set

- Read `docs/architecture/build-manifest.md`; parse the slice table (ID, Step,
  Depends on, Closes).
- Compute each slice's status from **GitHub PR merge state** (not git commit
  ancestry, and not any written status), checked in this order:
  - `done` — a merged PR exists for the head branch:
    `gh pr list --state merged --head slice/<ID> --json number` returns one.
  - `in-flight` — an open PR exists: `gh pr list --state open --head slice/<ID>`.
  - `todo` — neither.
- The **startable set** = every `todo` slice whose every dependency is `done`.

Dependencies are normally slice ids. The audit and Definition-of-Done gates use a
collective form instead, which must be expanded before the check:

- `all Step N` — every **other** slice whose Step column is `N`, sub-steps included
  (`all Step 3` covers 3a and 3b).
- `all prior` — every other slice in the table.

Deriving from PR merge state (not ancestry) is what keeps this correct under the
project's **Squash and Merge**: a squash rewrites the branch into one new commit on
`main`, so an ancestry check would report squashed slices as `todo` forever. Check
`done` before `in-flight` so a squash-deleted branch is read as done, not unstarted.

## 3. Check the set for phantoms

A row states work in prose, and prose goes stale: a row can claim a mechanism that
already exists, or a removal already made. Selecting one costs a whole session before
anyone notices, which has happened.

Most cleanup rows are baseline drains, and the baseline is machine-readable, so check
before offering: for each startable row that names files or entries it drains, confirm
those entries are still in `phpstan-baseline.neon` / `deptrac.baseline.yaml`. A row
whose claimed entries are gone is a **phantom** — report it as such rather than
offering it, and say what appears to have discharged it.

For a row that drains no baseline entry, spot-check its central claim against the code
before offering it. One `grep` is enough; the failure mode is a row asserting that
something is absent when it is present.

## 4. Safeguards (halt and report; do NOT proceed) if

- Nothing is startable — report done / blocked / in-flight counts and stop.
- A slice's branch is merged while a dependency is not — surface the state drift.

Slices already in flight do **not** block a report. Name them so the human can see what
is open, and let them decide whether to start another.

## 5. Report the set and wait

Report every startable slice as one line: id, what it does in plain english, and what
it discharges — baseline entries drained, or the scheduled slice it unblocks. Mark any
phantom found in step 3. Then **stop and wait** for the human to pick. Do not
recommend one unless asked.

Once a slice X is chosen, read its plan step in
`docs/architecture/0002-execution-plan.md` for the acceptance criteria and the RFC
sections it cites in `0001-foundational-architecture.md`. Describe the work in plain
english and **wait** for approval, clarification, or modification before writing
anything.

Lead that description with X's answer to the goal test: what two features could
disagree about without X, or which scheduled feature X unblocks. If you cannot write
that sentence from the plan, **stop and ask** — a slice whose purpose you cannot state
is one you will over- or under-build.

## 6. Implement X

- Create `slice/<X>` off `main`.
- TDD:
  - Behavior-preserving slice → add/extend the Step P parity fixtures **first**.
  - Seam-introducing slice → add its §8.1 enforcement rule in this slice.
  - Write failing tests, then implement to green.
- Keep commits small and logical (project rule). Run `composer test` to green.
- Build what X's acceptance requires and nothing beyond it. A defect, duplication, or
  rough edge noticed in passing is **reported, not solved** — duplication or
  divergence earns an `SC.*` row; a defect gets a GitHub issue plus a line in the
  final report saying whether the next slice can proceed while it stays open;
  generic tidiness gets one line in the PR body and neither a row nor a diff.
- Comments earn their place. Name the non-obvious fact each one carries that the code
  does not, or leave it out; length tracks the subtlety of the code, not its size.
  Reviewers raise violations as findings, so writing them costs a round.
- If you hit a fundamental design question the plan does not answer, **STOP and ask**
  — do not invent an interpretation.

## 7. Open the PR and report

- PR title carries no issue number; the body opens with what two features could have
  disagreed about without this change (or what it unblocks), then cites the slice id,
  plan step, and RFC section(s), and lists the acceptance criteria as a checklist.
- List manifest `Closes` candidates as "Candidate closes (pending review
  verification): #n" — do **not** wire `Closes #n` here; that is the reviewer's job
  after reading the issue body.
- Report the PR URL, and the startable set as it now stands, so the human can pick the
  next one without re-deriving it.
