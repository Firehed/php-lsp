---
name: review-slice
description: One cleanroom adversarial review pass (plus fixes) of a build slice branch for the RFC-1 / Plan-0002 execution. Reviews against the slice's acceptance criteria + RFC only, never the implementer's reasoning. Invoke with /review-slice [slice-id]; after /clear, re-run until a pass is clean, then land.
---

# review-slice — one cleanroom review pass (+ fix)

Execute "Mode B" of `docs/architecture/build-procedure.md`. **One invocation = one
pass.** If this pass fixes anything, the change needs another fresh pass — the user
runs `/clear` then `/review-slice` again. Only a pass that fixes nothing is "clean".

## 0. Read the goal first

Read **"The goal every slice serves"** in `build-procedure.md` before anything else.
The review exists to protect one thing: that two features cannot disagree about the
same symbol. Acceptance criteria are how a slice serves that goal, not a substitute
for it — a pass that checks every box while the change drifts from the goal has
failed, and so has a pass that generates work the goal does not call for.

Every finding you keep, and every fix you write, carries its one-sentence answer to
the goal test. No answer, no work.

## 1. Identify the slice

- If a slice id / branch is given, use `slice/<id>`. Otherwise find the single
  in-flight slice (an open, unmerged `slice/*` PR). If more than one, **STOP and ask**
  which.
- Check out the branch; ensure it is current with `main` (merge/rebase-free: if
  behind, merge `main` in or report).
- Compute the diff under review: `git diff main...slice/<id>`.
- Collect the slice's **owed items** from `0002`: anything the plan attaches by name to
  this slice id or its step — the step's owed regression tests, and the duplication a step
  is required to remove (e.g. Step 3b's "Regression tests the 3b corrections owe", Step
  4's "Named duplication this step must remove"). These are acceptance criteria, not
  suggestions; carry them into the panel verbatim. A slice that leaves one undone is a
  finding even if every criterion on its own row is met.

## 2. Cleanroom review (this is the safeguard — keep it clean)

Spawn independent reviewer subagents (the Agent tool) that see **only**: (a) the goal
section of `build-procedure.md`, (b) the slice's acceptance criteria from `0002`,
(c) the RFC sections it touches from `0001`, and (d) the diff. They **must not** be
given this conversation, the commit messages' reasoning, or any implementer rationale
— that is what makes it cleanroom. The goal is a published document, not rationale;
withholding it is what produces box-checking reviews.

Require every finding to carry its one-sentence goal-test answer, and tell the panel
that a finding without one is to be dropped rather than reported. The reviewer applies
that filter — it has the code in context; step 3 only backstops it.

Run a small diverse panel in parallel:

- **Acceptance** — is every acceptance criterion actually met, not just plausibly?
  Including each owed item from step 1: the named test exists, and it fails against the
  code it replaces (check that by reverting the fix locally, not by reading the test).
- **Conformance** — §8.1 conformance for the invariants the slice touches (no
  forbidden reflection/index access, no `instanceof` on a concrete `Type`, no branch
  on a resolved kind, no raw `initialize` params outside the negotiation component,
  as applicable).
- **Test strength** — would the tests actually **catch** the defect they imply? Name a
  mutation of the implementation (move a statement out of a `finally`, shorten a timed
  span, return a constant) and check whether anything fails. Spot-check that captured
  goldens assert the right values, not just that they diffed clean.
  Note: instruct the reviewer to revert mutations through the edit tool, not git.

Every reviewer also checks the comments and docblocks the diff adds or grows: each
must name a fact the code does not convey, with length tracking the subtlety of the
code rather than its size (`CLAUDE.md`). Raise these as findings, not nits — they
carry the standing goal-test answer from the goal section (two copies of one fact
drifting apart), so the drop rule above does not apply to them.

Each returns structured findings; then have them adversarially try to break the
change.

### Do not re-do CI's job

CI already runs the suite, PHPStan, PHPCS, and coverage on every push. A finding whose
evidence is "the suite passes", "coverage is N%", or "this line is uncovered" is out of
scope — drop it. Reviewers must not run a coverage driver or report per-file
percentages.

What CI *cannot* judge is what the panel is for: whether a recorded claim is true,
whether an assertion would survive a mutation, whether an acceptance criterion is
genuinely met. An unexercised branch is still worth raising — but as "nothing would
catch X", with the mutation named, not as a coverage number.

### Constrain the subagents' commands

State in every subagent prompt that it may run **only** the project's composer scripts:

```
composer test                                        # full check
composer unit -- --filter X                          # one test
composer phpstan -- --error-format=raw --no-progress
composer phpcs -- -q --report=emacs
```

Raw equivalents (`vendor/bin/phpunit`, `php -d … vendor/bin/phpunit`) bypass the
approved-command list and fire a permission prompt per call.

Reviewers already receive `CLAUDE.md` automatically and default to these scripts on
their own. They defect in two specific situations, so head both off:

- **Throwaway measurement harnesses.** Put them in `tests/`, run them with
  `composer unit -- --filter <name>`, and delete them before reporting. Do not write
  them to the scratchpad, which forces a positional path and a raw invocation.
- **Interpreter-level flags** (`php -d pcov.enabled=0 …`). `composer unit` runs PHP
  through Composer's own process, so an `-d` override cannot reach the child. There is
  no approved route to this — if a finding needs one, **report that and stop** rather
  than invoking the binary. Keeping coverage out of scope removes most of the motive.

Scale the panel to the diff. A docs-and-one-class slice does not need agents that each
burn 75k+ tokens re-deriving the whole measurement.

## 3. Verify and fix

- Keep only findings you can **confirm against the code**; discard speculation.
- Backstop the goal test on what survives the panel. A finding that cannot answer it
  (comment discipline carries its standing answer) is reported in one line as
  noted-not-fixed; nothing gets built for it. Noted lines do not make the pass dirty
  — only an applied fix forces a fresh pass. This is what lets the loop terminate.
- Weigh each fix against the defect it prevents. When the change needed is wider than
  the defect — a production API change, a new fixture dependency, a reshaped seam —
  **state the cost and ask** rather than building it. That call is the user's.
- If any survive: fix them on the branch in small commits; run `composer test` to
  green. Report: **"Pass found N issues, fixed and committed: [...]. Run /clear then
  /review-slice again to verify."** STOP — do not land. A fix needs a fresh pass.

## 4. If the pass is clean (nothing needed a fix)

- For each `Closes` candidate in the manifest for this slice: `gh issue view <n>`,
  confirm its acceptance criteria are met **by this change**; if so, add `Closes #<n>`
  to the PR body with a one-line verification note. If not met, leave it and say why.
- `gh pr ready` if the PR was a draft.
- Report: **"Pass clean. Verified closes: #n. Ready to land — merge when ready."**
  Do **not** auto-merge — merging is irreversible and outward-facing; the user lands.
- Report the next computed slice for after landing (highest `Kind` in the startable
  set), and the rest of that set in one line each.

## 5. Close every pass against the goal

The last thing the report says, whether the pass was clean or not, is how this change
serves the goal — in plain english, not slice ids:

- **What two features could have disagreed about**, and what now makes that
  impossible rather than merely unlikely. Name the mechanism that would fail.
- **What the change unblocks**, if it is groundwork rather than a fix — which
  scheduled feature could not be built until this shape existed.
- **What corrections remain** before the change actually gets there, if it does not
  yet. A slice that meets every criterion and still leaves a way for two features to
  disagree is not finished, and this is where that gets said.

A pass that cannot write this paragraph has not understood the change well enough to
approve it. Say so instead of approving it.
