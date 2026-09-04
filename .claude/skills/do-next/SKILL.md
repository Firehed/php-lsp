---
name: do-next
description: Implement the next unticked step of docs/architecture/build-manifest.md as one draft PR on branch step/<n>. Invoke with /do-next.
---

# do-next

1. Preconditions. `git status` shows no tracked change. `git checkout main` and `git pull --ff-only`. `composer test` is green. If any of these fails, stop and report it.
2. Pick. Open `docs/architecture/build-manifest.md`. The step is the first row whose box is unticked. If `gh pr list --head step/<n>` shows an open PR, stop and report it.
3. Read. The row's text and its `Done` clause are the whole specification. Read the code the row names. Read RFC 1 §4 if the row cites a section. Do not read plan 0002, the old manifest history, or commit messages for intent. If a fact the row states about the code is false, or a thing it names does not fit the row's own words (a fixture that is not the scenario the row describes, a method that no longer exists), stop and report; do not choose an interpretation.
4. Route check. Open `tests/Architecture/OneRoutePerFactTest.php` (once step-32 exists). For each fact the row touches, note its ingredient classes and its one holder. The change may name an ingredient only inside that holder or an assembly root; where the row deletes a route, the ledger's skip for it goes too. If the change needs an ingredient anywhere else, the design is wrong: stop and report.
5. Branch `step/<n>` from `main`.
6. Build it. Write the test for each `Done` clause first, then the change. Small commits: one rename, one type, one test. Run `composer test` before every commit.
7. Do not add a PHPStan rule, a deny entry, an allowlist path, a deptrac edge, a baseline entry, an `@phpstan-ignore`, an RFC paragraph, or a skipped test case, unless the row names that exact edit. Do not remove or weaken a rule, a deny entry, a layer, or a test. Removing an allowlist path, a baseline entry, or a skip the row clears is fine and expected. If the step cannot be finished without an edit the row does not name, stop, report which one and why, and leave the branch as it is.
8. Do not fix anything outside the row. Something wrong nearby becomes a GitHub issue, named in the report.
9. Tick the row's box in the manifest in the last commit.
10. Open a draft PR from `step/<n>`. Title: the row's first sentence. Body: the `Done` clauses as a checklist, each naming the test or CI check that proves it, then `Closes #n` for every issue the `Done` clause names.
11. Report the PR URL and the next step number. Stop.
