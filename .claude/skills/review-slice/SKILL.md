---
name: review-slice
description: One review pass of a step PR against its Done clause, with fixes. Invoke with /review-slice <n>.
---

# review-slice

1. `git fetch origin` and `git checkout step/<n>`. If the branch is behind `main`, merge `main` in and run `composer test`.
2. Read the step's row in `docs/architecture/build-manifest.md` and the diff: `git diff main...step/<n>`.
3. Spawn one reviewer subagent. Give it only the row text, RFC 1 §4, the diff, and the four questions below. Do not give it this conversation or the commit messages. Tell it to run only `composer test`, `composer unit -- --filter X`, `composer phpstan -- --error-format=raw --no-progress`, and `composer phpcs -- -q --report=emacs`.
   - Done: is each `Done` clause true, and which test or CI check proves it? A clause with no proof is a finding.
   - Freeze: does the diff add a PHPStan rule, deny entry, allowlist path, deptrac edge, baseline entry, `@phpstan-ignore`, or skipped test case that the row does not name — or remove or weaken a rule, a deny entry, a layer, or a test? Each one is a finding. Removing an allowlist path, a baseline entry, or a skip the row clears is fine.
   - One implementation: does the diff leave a second implementation of the question it collapses? Name the file and method.
   - One route: for each family in `tests/Architecture/OneRoutePerFactTest.php`, does any class in the diff other than the composition root name an implementation, call a static method on one, or bind a consumer to a concrete class instead of the interface? Is a new composite named `Composite<Interface>`, placed in the family's namespace, and free of logic beyond ordering its members? For a confinement row, does any class outside its holders name a route class? Does any class hold two routes to one fact or branch on which route answered (a null check or empty check on one route before calling another)? Name the file and method.
   - Skips: for every skipped case the diff adds or leaves in the ledger and the grid, does the case run its assertion before it skips? Remove the skip entry and run the test: a pass means the skip hid a green case, and that is a finding.
   - Fixtures: where a test tells states or routes apart by fixture, do the fixtures differ only in the property that defines the state, and does an assertion pin that property for each one? A fixture that fits because it exists, not because it matches the row's words, is a finding.
   - Strength: change one line of the new code so it is wrong. Does a test fail? If not, that is a finding. Revert with the edit tool, not git.
4. Confirm each finding against the code yourself. Drop what you cannot confirm.
5. Fix confirmed code findings on the branch in small commits, `composer test` green. Do not edit the manifest row, a skill, a rule, or an allowlist to make a finding go away; a finding that needs one of those is reported, not fixed.
6. Report, in this order: findings fixed; findings left and why; whether every `Done` clause holds. If nothing is left, run `gh pr ready`. The human merges.
