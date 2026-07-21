---
name: review-slice
description: One cleanroom adversarial review pass (plus fixes) of a build slice branch for the RFC-1 / Plan-0002 execution. Reviews against the slice's acceptance criteria + RFC only, never the implementer's reasoning. Invoke with /review-slice [slice-id]; after /clear, re-run until a pass is clean, then land.
---

# review-slice — one cleanroom review pass (+ fix)

Execute "Mode B" of `docs/architecture/build-procedure.md`. **One invocation = one
pass.** If this pass fixes anything, the change needs another fresh pass — the user
runs `/clear` then `/review-slice` again. Only a pass that finds nothing is "clean".

## 1. Identify the slice

- If a slice id / branch is given, use `slice/<id>`. Otherwise find the single
  in-flight slice (an open, unmerged `slice/*` PR). If more than one, **STOP and ask**
  which.
- Check out the branch; ensure it is current with `main` (merge/rebase-free: if
  behind, merge `main` in or report).
- Compute the diff under review: `git diff main...slice/<id>`.

## 2. Cleanroom review (this is the safeguard — keep it clean)

Spawn independent reviewer subagents (the Agent tool) that see **only**: (a) the
slice's acceptance criteria from `0002`, (b) the RFC sections it touches from `0001`,
and (c) the diff. They **must not** be given this conversation, the commit messages'
reasoning, or any implementer rationale — that is what makes it cleanroom. Run a small
diverse panel in parallel:

- **Acceptance** — is every acceptance criterion actually met, not just plausibly?
- **Conformance** — §8.1 conformance for the invariants the slice touches (no
  forbidden reflection/index access, no `instanceof` on a concrete `Type`, no branch
  on a resolved kind, no raw `initialize` params outside the negotiation component,
  as applicable).
- **Coverage** — does the parity harness / enforcement rule actually **exercise** the
  change? Green is not enough; an unexercised branch is a gap (Step P). Spot-check
  that captured goldens assert the right values, not just that they diffed clean.

Each returns structured findings; then have them adversarially try to break the
change.

## 3. Verify and fix

- Keep only findings you can **confirm against the code**; discard speculation.
- If any survive: fix them on the branch in small commits; run `composer check` to
  green. Report: **"Pass found N issues, fixed and committed: [...]. Run /clear then
  /review-slice again to verify."** STOP — do not land. A fix needs a fresh pass.

## 4. If the pass is clean (no surviving findings)

- For each `Closes` candidate in the manifest for this slice: `gh issue view <n>`,
  confirm its acceptance criteria are met **by this change**; if so, add `Closes #<n>`
  to the PR body with a one-line verification note. If not met, leave it and say why.
- `gh pr ready` if the PR was a draft.
- Report: **"Pass clean. Verified closes: #n. Ready to land — merge when ready."**
  Do **not** auto-merge — merging is irreversible and outward-facing; the user lands.
- Report the next computed slice for after landing.
