# 000x: Known Defects Are Tests

Status: accepted.

## Decision

A defect reproduced through the end-to-end tier is kept as a script in `tests/EndToEnd/defects/`, written as the behavior it should have.
The suite requires each defect script to fail at a step, by a wrong answer or no answer.
A defect script that passes fails the suite until it moves to `tests/EndToEnd/scripts/`, where it binds like any other script, or is deleted if a script there already covers it.
A defect script that fails any other way, such as a missing marker or output on stderr once its steps pass, is an error.

Cases move one at a time, so a partial fix promotes only what it fixes.
A defect script is named for its issue when one exists.

## Context

A defect found while working on something else used to leave behind a reproduction in prose, in an issue.
The prose went stale as the code moved, and whoever picked the issue up rebuilt the reproduction from scratch.

A reproduction kept as a test is checked on every run.
It cannot go stale silently: it either still fails, passes and must be promoted, or breaks and must be fixed.
Its promotion is the failing test of the fix that resolves it.
A fix that resolves a defect by accident is noticed, because the suite fails until the defect is promoted.

The end-to-end tier is the one place for this because its scripts are data: a reproduction is the same script before and after its fix, and only its directory changes.
