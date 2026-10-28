# 9: Temporary Known Defects

Status: accepted

## Decision

It is acceptable to move a passing end-to-end test temporarily into the known-defects suite (ADR 8) if it simplifies the process of executing a multi-stage refactor or other large project.

Authors must justify the temporary regression in the pull request making the change, and explain why it should be temporary.
Reviewers must exercise their judgment as to whether this is an acceptable change.

These temporary regressions should not be added into a tagged release.
The release manager should include a check for this as part of the release process.

## Context

The project has kept to very high test coverage standards.
However, trying to meet the letter of the standards sometimes gets in the way of making bigger changes.
Sometimes it's _way_ easier, simpler, or smaller to take a small break during a refactor and fast-follow fix it.

By moving the test into a known-failing location, no information is lost.
The test remains intact, and retains all the benefits of ADR 8's rationale.
Disabled tests get forgotten, and deleted tests will likely never return.
