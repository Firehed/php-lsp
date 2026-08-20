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

## The goal every slice serves

The rework exists to eliminate one bug class: two features disagreeing about the same symbol.
Hover resolves a name completion never offers; definition works on a node type that type-inference does not.
That is #190, #253 and #256, and the cause is M×N hand-written pairs — M consumers × N node types or symbol kinds — that drift apart.

The method is one authority per question, so consistency holds by construction rather than by discipline.
Every seam ships with the enforcement that makes drift fail loudly; a rule without a mechanism is not done (§8.1).

Everything else — fewer lines, tidier layering, better names — is a means.
It counts when it serves that end or unblocks a feature the plan schedules, and not otherwise.

### The goal test

Before writing a change or raising a finding, answer in one sentence:

> What can two features disagree about if this is not done — or which scheduled feature does it unblock?

No answer means out of scope.
Duplication or divergence earns an `SC.*` row — that is the goal's own subject matter.
A defect gets a GitHub issue, and the session's stop report says whether the next slice can proceed while it stays open — never leave the human to derive that.
Generic tidiness gets at most one line in the PR body or pass report, and neither a row nor a diff.

These are the ways the test gets failed in practice, each observed:

- Enforcement built for a defect nobody can produce.
- A mechanism widened to catch a risk belonging to a different class of bug.
- Production API changed to improve the shape of a test.
- Tidiness fixed or filed on the way past because it was noticed.

A fix whose blast radius exceeds the defect it prevents is a decision for the human, not a reflex.
State what it would cost and ask.

### Prefer the simpler version

Abstractions are how the M×N goes away, so this is not an argument against them.
It is an argument against generality nobody asked for: a parameter no caller varies, an extension point with one implementation, a seam for a case that is not on the plan.
Those cost as much to maintain as the real ones and buy nothing.

Before settling on an implementation, check whether a smaller one makes the same disagreement impossible.
It usually does.

### Comments earn their place

A comment that restates its code is a second copy of it, and the copy is the one that goes stale.
The project rule already says this (`CLAUDE.md`): name the non-obvious fact a comment carries that the code does not, or delete it, and let length track how subtle the code is rather than how much of it there is.
It is stated here because it is the rule this project ignores most often.

Comment discipline carries a standing goal-test answer — two copies of one fact drifting apart is this rework's bug class in miniature — so these findings pass the filter without a per-finding sentence.

## Source of truth: git, not a status field

Progress is **derived from git / PR merge state**, keyed by deterministic branch
names — not from a hand-maintained "status" column, which drifts the moment a merge
happens outside the tool.

- The **manifest** (`build-manifest.md`) is a *static* slice registry: id, step,
  title, dependencies, deterministic branch name, and which existing issues a slice
  closes. It is append-only as later phases are reached; it never records progress.
- A slice's **status is computed**, checked in this order:
  - `done` — a **merged PR** exists whose head ref is `slice/<slug>`
    (`gh pr list --state merged --head slice/<slug>`).
  - `in-flight` — an **open PR** exists for `slice/<slug>`.
  - `todo` — neither.
- The **next slice** = the first `todo` row in the ordered list. List order is
  dependency order — each row starts when the one above it merges.
- **Preflight sanity check:** every row above the next slice should be `done`. If any
  is not, report the state drift and halt.

Because status is computed from merge reality, a cold session cannot be misled by a
stale field, and nothing needs updating by hand.

**Squash-merge safety.** Status is derived from GitHub's **PR merge state**, which is
set identically for squash, rebase, and merge-commit — not from git commit ancestry.
This matters because the project lands everything via *Squash and Merge*, which
rewrites a branch into one new commit on `main`: an ancestry check ("are the branch's
commits in `main`?") would report every squashed slice as `todo` forever, whereas the
merged-PR check is correct. Branch auto-deletion on merge is also fine — the merged PR
record keeps its `headRefName` after the branch is gone, so it is still found by
`slice/<slug>`. Checking `done` (merged PR) *before* `in-flight` ensures a
squash-deleted branch is never misread as unstarted.

## Conventions

- **One slice = one branch = one PR.** Branch name is fixed by the manifest:
  `slice/<slug>` (e.g. `slice/enforcement-rules`). This is what lets a *review*
  session find "this step's branch" unambiguously from the slug alone.
- **PR body** opens with the slice's goal-test answer, then cites the slice slug, its
  plan step, and the RFC section(s) it satisfies.
- **`Closes #<n>`** is added to a PR **only after the reviewer has read that issue's
  body and confirmed its acceptance criteria are met** — never inferred from a title
  (per the project's review rules).
- **Acceptance criteria** for a slice are its plan step's criteria in 0002; the
  manifest points at the step, it does not restate them.

## Mode A — "do the next step"

1. **Preconditions (halt if unmet).** Working tree clean; on `main`; `main` synced
   with origin; `composer test` green on `main`. If any fails, report and stop.
2. **Find X.** Parse the manifest; compute each slice's status from merged-PR state;
   `X` is the first `todo` row.
3. **Preflight X.** Every row above X should be `done`. If any is not, report the
   state drift and halt.
4. **Screen X for phantoms.** A row states its work in prose, which goes stale — it can
   claim a mechanism that already exists or a removal already made, and selecting one
   costs a session before anyone notices. If X names baseline entries it drains, confirm
   they are still there; if it drains none, spot-check its central claim against the
   code. Report a phantom and ask whether to remove it and continue to the next row.
5. **Explain X.** Describe in plain english the work to be done, lead with X's answer
   to the goal test. Then wait for approval, clarification, or modification.
6. **Implement X.** Create `slice/<slug>`; work the plan-step's acceptance under TDD
   (for a behavior-preserving step: parity fixtures first; for a step that
   introduces an invariant seam: its §8.1 enforcement rule in the same slice); run
   `composer test`; open a PR citing X. Build what X's acceptance requires and
   nothing beyond it — a problem noticed in passing is reported, not solved (a new
   manifest row for duplication, a GitHub issue plus a can-the-next-slice-proceed
   call for a defect, a line in the PR body for tidiness).
7. Stop. Report the PR and the *next* slice (the row below this one), so the human
   knows what a follow-up "do the next step" would pick up.

## Mode B — "review this step's branch"

1. **Identify the slice.** The slug given, or the single `in-flight` one. If more than
   one is in flight and no slug was given, stop and ask which. Check out its branch.
2. **Cleanroom review.** A fresh reviewer (subagent) sees **only** the goal section
   of this document, the slice's acceptance criteria, the relevant RFC sections, and
   the diff — **not** the implementer's reasoning or this conversation. It adversarially verifies:
   - every acceptance criterion is actually met (not just plausibly);
   - §8.1 conformance for the invariants the slice touches;
   - the parity harness / enforcement rule would **actually catch a regression in**
     the change — name a mutation of the implementation and check that something
     fails (per Step P); for a slice touching a parity surface, run
     `composer parity-coverage` and treat any unexecuted surface line as a corpus
     gap to explain or fill;
   - it then tries to break the change.

   The reviewer does **not** re-check what CI already enforces: a green suite,
   PHPStan, PHPCS, coverage percentages. Those run on every push. Review effort goes
   where CI is blind — unverified claims, assertions that survive mutation,
   acceptance criteria met only in appearance.
3. **Apply the goal test.** Every surviving finding carries its one-sentence answer.
   A finding without one is reported as noted-not-fixed, in a line, and nothing is
   built for it. Noted lines do not make a pass dirty — only an applied fix forces a
   fresh pass, which is what lets the review loop terminate.
4. **Fix.** Apply fixes on the branch; the change then needs a fresh pass (a new
   session) before landing. Land only from a pass that fixed nothing.
5. **Land.** Mark ready; **check the slice's box in the manifest** (one diff, one
   review). For each existing issue the manifest says this slice closes, **read the
   issue body, confirm its criteria are met, then** wire `Closes #<n>` (or close with
   a verification note). Merge.
6. **Close against the goal.** Every pass ends by saying, in plain english, what two
   features could have disagreed about and what now makes that impossible — or what
   the change unblocks, if it is groundwork — or what corrections remain before it
   gets there. A pass that cannot write that paragraph has not understood the change
   well enough to approve it, and says so instead.
7. Stop. Report what merged and the next slice (the row below this one).

## The "X is always correct" guarantee, in one place

- What may start is **computed from git truth**, so a cold session cannot pick the
  wrong one from a stale note. List order is dependency order — the first unmerged
  row is the next slice.
- **A row's claim is screened before it is built**, so a phantom — work already
  discharged by something else — is reported rather than discovered mid-slice.
- The driver **halts and asks** at every fork it cannot resolve safely (unmet
  precondition, nothing unblocked, state drift, a review it cannot make clean).
- **Deterministic branch names** mean the review session always finds the right
  branch from the slug.

## Relationship to GitHub issues

The manifest is the driver's source of truth. GitHub issues are the *human-facing*
mirror and the mechanism for closing pre-existing issues (the `Closes` column).
Create the per-slice issue when its phase is reached (just-in-time, one phase ahead
— not the whole tree up front). Existing design epics (#264/#265/#266) are reused as
the later-phase trackers, not duplicated.
