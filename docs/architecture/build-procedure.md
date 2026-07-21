# Build Procedure (driving RFC 1 / Plan 0002 across sessions)

    Status:   Draft (process)
    Depends:  0001-foundational-architecture.md, 0002-execution-plan.md
    Date:     2026-07-20

## Purpose

Execute the plan across many short sessions without holding state in your head.
Two commands:

- **"do the next step"** — implement the correct next slice.
- **"review this step's branch"** — cleanroom-review and fix a slice, in a fresh
  session.

The safeguard that makes this usable when you are juggling other work: **the next
step is computed, never remembered.** A session determines what to do from durable,
checkable state and *halts and asks* when that state is ambiguous. This is one or
two notches below hands-off autonomy by design.

## Source of truth: git, not a status field

Progress is **derived from git / PR merge state**, keyed by deterministic branch
names — not from a hand-maintained "status" column, which drifts the moment a merge
happens outside the tool.

- The **manifest** (`build-manifest.md`) is a *static* slice registry: id, step,
  title, dependencies, deterministic branch name, and which existing issues a slice
  closes. It is append-only as later phases are reached; it never records progress.
- A slice's **status is computed**, checked in this order:
  - `done` — a **merged PR** exists whose head ref is `slice/<id>`
    (`gh pr list --state merged --head slice/<id>`).
  - `in-flight` — an **open PR** exists for `slice/<id>`.
  - `todo` — neither.
- The **next slice** = the first `todo` in the manifest whose dependencies are all
  `done`.

Because status is computed from merge reality, a cold session cannot be misled by a
stale field, and nothing needs updating by hand.

**Squash-merge safety.** Status is derived from GitHub's **PR merge state**, which is
set identically for squash, rebase, and merge-commit — not from git commit ancestry.
This matters because the project lands everything via *Squash and Merge*, which
rewrites a branch into one new commit on `main`: an ancestry check ("are the branch's
commits in `main`?") would report every squashed slice as `todo` forever, whereas the
merged-PR check is correct. Branch auto-deletion on merge is also fine — the merged PR
record keeps its `headRefName` after the branch is gone, so it is still found by
`slice/<id>`. Checking `done` (merged PR) *before* `in-flight` ensures a
squash-deleted branch is never misread as unstarted.

## Conventions

- **One slice = one branch = one PR.** Branch name is fixed by the manifest:
  `slice/<id>` (e.g. `slice/S2.1`). This is what lets a *review* session find "this
  step's branch" unambiguously from the id alone.
- **PR body** cites the slice id, its plan step, and the RFC section(s) it satisfies.
- **`Closes #<n>`** is added to a PR **only after the reviewer has read that issue's
  body and confirmed its acceptance criteria are met** — never inferred from a title
  (per the project's review rules).
- **Acceptance criteria** for a slice are its plan step's criteria in 0002; the
  manifest points at the step, it does not restate them.

## Mode A — "do the next step"

1. **Preconditions (halt if unmet).** Working tree clean; on `main`; `main` synced
   with origin; `composer test` green on `main`. If any fails, report and stop.
2. **Compute X.** Parse the manifest; compute each slice's status from merged-PR
   state; `X` = first `todo` whose dependencies are all `done`.
3. **Safeguards (halt and ask, do not guess) if:**
   - nothing is unblocked (report how many are `done` / blocked / in-flight);
   - a slice is already `in-flight` that is not yet `done` (finish or review it
     first — one slice in flight at a time);
   - the manifest references a merged branch for a slice whose dependencies are not
     merged (state drift — surface it).
4. **Implement X.** Create `slice/<X>`; work the plan-step's acceptance under TDD
   (for a behavior-preserving step: parity fixtures first; for a step that
   introduces an invariant seam: its §8.1 enforcement rule in the same slice); run
   `composer test`; open a PR citing X.
5. Stop. Report the PR and the *next* computed slice, so the human knows what a
   follow-up "do the next step" would pick up.

## Mode B — "review this step's branch"

1. **Identify the slice.** The `in-flight` one, or the id given. Check out its
   branch.
2. **Cleanroom review.** A fresh reviewer (subagent) sees **only** the slice's
   acceptance criteria, the relevant RFC sections, and the diff — **not** the
   implementer's reasoning or this conversation. It adversarially verifies:
   - every acceptance criterion is actually met (not just plausibly);
   - §8.1 conformance for the invariants the slice touches;
   - the parity harness / enforcement rule would **actually catch a regression in**
     the change — name a mutation of the implementation and check that something
     fails (per Step P);
   - it then tries to break the change.

   The reviewer does **not** re-check what CI already enforces: a green suite,
   PHPStan, PHPCS, coverage percentages. Those run on every push. Review effort goes
   where CI is blind — unverified claims, assertions that survive mutation,
   acceptance criteria met only in appearance.
3. **Fix.** Apply fixes on the branch; re-run the cleanroom pass until clean.
4. **Land.** Mark ready / merge. For each existing issue the manifest says this slice
   closes, **read the issue body, confirm its criteria are met, then** wire
   `Closes #<n>` (or close with a verification note).
5. Stop. Report what merged and the next computed slice.

## The "X is always correct" guarantee, in one place

- Next step is **computed from git truth**, so a cold session cannot pick the wrong
  one from a stale note.
- The driver **halts and asks** at every fork it cannot resolve safely (unmet
  precondition, nothing unblocked, a slice already in flight, state drift, a review
  it cannot make clean).
- **Deterministic branch names** mean the review session always finds the right
  branch from the id.
- **One slice in flight at a time** keeps "the next step" unambiguous.

## Relationship to GitHub issues

The manifest is the driver's source of truth. GitHub issues are the *human-facing*
mirror and the mechanism for closing pre-existing issues (the `Closes` column).
Create the per-slice issue when its phase is reached (just-in-time, one phase ahead
— not the whole tree up front). Existing design epics (#264/#265/#266) are reused as
the later-phase trackers, not duplicated.
