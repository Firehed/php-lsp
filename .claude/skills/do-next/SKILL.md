---
name: do-next
description: Implement the next build slice for the RFC-1 / Plan-0002 execution. Reads docs/architecture/build-manifest.md, computes the correct next slice from git/PR merge state (never a status field), enforces preconditions, and implements it under TDD on a slice/<id> branch. Invoke with /do-next.
---

# do-next — implement the next build slice

Execute "Mode A" of `docs/architecture/build-procedure.md`. **Do not guess; halt and
ask on any ambiguity.** The whole point is that a cold session picks the correct next
slice from durable state, not from memory.

## 1. Preconditions (halt and report if any fail)

- `git status`: the **tracked** working tree must be clean. Untracked scratch files
  (e.g. `notes.txt`, coverage output) are fine. If tracked files are modified, stop.
- Switch to `main` and sync: `git fetch origin`; if `main` is behind, fast-forward;
  if diverged, stop and report.
- Verify the base is green: run `composer test`. If red, **stop** — do not build on
  a red base.

## 2. Compute the next slice X

- Read `docs/architecture/build-manifest.md`; parse the slice table (ID, Step,
  Depends on, Closes).
- Compute each slice's status from **GitHub PR merge state** (not git commit
  ancestry, and not any written status), checked in this order:
  - `done` — a merged PR exists for the head branch:
    `gh pr list --state merged --head slice/<ID> --json number` returns one.
  - `in-flight` — an open PR exists: `gh pr list --state open --head slice/<ID>`.
  - `todo` — neither.
- `X` = the first `todo` slice whose every dependency is `done`.

Deriving from PR merge state (not ancestry) is what keeps this correct under the
project's **Squash and Merge**: a squash rewrites the branch into one new commit on
`main`, so an ancestry check would report squashed slices as `todo` forever. Check
`done` before `in-flight` so a squash-deleted branch is read as done, not unstarted.

## 3. Safeguards (halt and report; do NOT proceed) if

- No slice is unblocked — report done / blocked / in-flight counts and stop.
- Any slice is `in-flight` and not `done` — one slice in flight at a time; finish or
  review it first.
- A slice's branch is merged while a dependency is not — surface the state drift.

## 4. Implement X

- Create `slice/<X>` off `main`.
- Read X's plan step in `docs/architecture/0002-execution-plan.md` for its acceptance
  criteria, and the RFC sections it cites in `0001-foundational-architecture.md`.
- TDD:
  - Behavior-preserving slice → add/extend the Step P parity fixtures **first**.
  - Seam-introducing slice → add its §8.1 enforcement rule in this slice.
  - Write failing tests, then implement to green.
- Keep commits small and logical (project rule). Run `composer test` to green.
- If you hit a fundamental design question the plan does not answer, **STOP and ask**
  — do not invent an interpretation.

## 5. Open the PR and report

- PR title carries no issue number; the body cites the slice id, plan step, and RFC
  section(s), and lists the acceptance criteria as a checklist.
- List manifest `Closes` candidates as "Candidate closes (pending review
  verification): #n" — do **not** wire `Closes #n` here; that is the reviewer's job
  after reading the issue body.
- Report the PR URL and the **next** computed slice, so a follow-up `/do-next` is
  predictable.
