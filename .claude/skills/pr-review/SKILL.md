---
name: pr-review
description: Review a pull request before it leaves draft - a simplify pass, a correctness review, and a check against the project's design intent (one route per fact, no work that exists only to get past a tool). Run it in a fresh agent with no history of the work being reviewed.
---

# PR review

Review one pull request (by number, or the current branch against `main`) and report findings. Change nothing: the session that owns the pull request decides what to fix.

## Before reviewing

1. Read `AGENTS.md` (especially Design and Staying on track), `CONTRIBUTING.md`, and the ADRs in `docs/adrs`.
2. Read the pull request description and every issue it says it closes, with `gh`.
3. Get the full diff against its base branch.

## Passes

Run all of them and report each separately.

1. **Simplify.** Run the `simplify` skill on the diff, reporting its suggestions instead of applying them.
2. **Correctness.** Run the `code-review` skill on the pull request at high effort, reporting only.
3. **Design intent.** For each piece of new code that answers a question (what a symbol is, where it is declared, what type an expression has, what is at a position), search the codebase for a place that already answers that question. Report:
   - **Second routes:** the existing place, the new one, and how they could disagree.
   - **Syntax reads:** new code outside the one walker that walks the syntax tree or reads facts from syntax nodes, whether or not another route exists.
   - **Deferrals:** a comment or pull request text that knowingly leaves a structural problem in place for later.
   - **Tool workarounds:** code shaped to get past PHPStan, deptrac, or a failing test rather than to solve the problem.
   - **Scope:** changes that do not serve the issues the pull request closes, and "Done when" criteria it does not meet.
4. **Remaining routes.** For each fact the pull request gives one owner, search all of `src/`, not only the changed files, for code that still decides any part of it, directly or by re-deriving it from something it was handed. Report each candidate with a concrete input, and say whether it decides this fact or a different one.

## Rules

- Verify every finding against the code with a concrete input or scenario before reporting it; mark anything unverified as such.
- Keep findings terse: file, the problem, the failing input. No research narrative.
- Mark each finding as introduced by this pull request or already present on the base branch.
- An empty report is a valid result.
