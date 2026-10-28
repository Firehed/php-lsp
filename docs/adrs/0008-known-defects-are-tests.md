# 8: Known Defects are Added as Tests

Status: accepted.

## Decision

When a reproducible defect is discovered and a fix is out-of-scope for the work being performed, the reproduction script is tracked as a known-defect case.
This addition MAY be included in the original change where it was discovered, or can be given its own PR.

Known-defect cases are end-to-end tests that live in `tests/EndToEnd/defects/`, written with the intended behavior of the program.
When the defect is resolved, the script moves to the standard home for end-to-end tests: `tests/EndToEnd/scripts`.

Known-defect cases have a runner that inverts the pass/fail logic: while it lives in `defects` it _must_ fail; a defect case that's passing will cause the suite to fail.

A defect script is named for its issue when one exists.
The author may use their own discretion as to whether it should be renamed or keep the issue number upon being fixed and moving into the standard suite.

## Context

It's common to discover bugs unrelated to the task at hand.
Trying to fix them mid-change often leads to scope creep.

Capturing them solely as an issue can cause details to get lost.
Further, if a change inadvertently fixes a known issue, the issue remains open until someone discovers it was fixed.
With the inverted pass/fail logic on the defect suite, an unintended fix is actively highlighted when running the test suite and in CI.

Functionally, this serves as something akin to PHPStan's baseline, but for end-to-end tests.
It also serves as a sort of inverted-TDD: a fix for a known defect will turn that suite red, and moving it into the normal suite brings it green again.

### Alternatives considered

- Creating tests and marking as skipped proactively.
  This does not have a way to proactively check if any skipped tests are now passing.
- Issues only.
  Tried countless times; details get lost and issues go stale.

