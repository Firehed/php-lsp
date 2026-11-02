---
name: fix-a-fact
description: Fix, consolidate, or add how the server answers one question (a fact) so it matches PHP and has one route - find every route, review a design adversarially before code, build test-first against an oracle, and review adversarially again before the pull request leaves draft. Use for any bug fix or refactor that changes what the server says about code.
---

# Fix a fact

A fact is one question the server answers: what a member access is, which members a class has, what type an expression has.
Two rules govern every step: do what PHP does, and have one route to the fact.
Finishing means one route remains, nothing is left dangling, and the answer matches PHP.

## 1. Pin down the fact and PHP's behavior

1. State the fact as a question PHP's semantics answer, in plain terms.
2. Find what PHP does: the manual, or php-src for precise rules.
   Where the manual is vague or versions may differ, write a short script for the maintainer to run on 3v4l across versions; do not run ad hoc PHP here.
3. If PHP's behavior differs by version, stop and ask which to follow.

## 2. Find every route

1. Name the closed set of code that can decide the fact: every file handling the syntax node types or domain types involved (grep for them), and every consumer of the types that carry the answer.
2. Read all of it.
   A route is any code that decides any part of the fact, including code that re-derives it from something it was handed.
3. Write the routes down.
   If a route decides the fact for code PHP rejects outright, it needs no answer; do not invent one.

## 3. Design, then attack the design

1. Write a short design in the scratchpad: the one owner, what it replaces, the rules it follows with PHP behavior stated plainly, and what is out of scope with the issue that tracks it.
2. Fix at the root.
   If the routes disagree because a lower-level fact is wrong (an edge, a domain field), that fact is fixed first, in its own pull request.
3. Give the design to a fresh agent with the prompt below, verify each finding yourself against PHP or the code, revise, and repeat until a round finds nothing that holds.

> Adversarially review the design at `<path>`. The project's two rules: do what PHP does, and one route per fact. Try to prove the design wrong: (1) find any rule or step that differs from PHP for code PHP accepts, with a concrete PHP example; (2) find any part of the fact that would still be decided in more than one place in `src/`, or that the design itself decides twice; (3) find anything left dangling, and any cycle or bad input that could crash the server. Do not edit files. Report verified findings only, each with evidence and a concrete example.

## 4. Build

1. Work test-first.
   Prefer an oracle over hand-written expectations: PHP's own reflection checks member facts (`MemberResolverTypeGraphTest`).
2. Reuse existing fixtures before adding any; extend a shared fixture additively when a new case fits it.
3. Confirm each new test fails without the change.
4. Keep commits small: tests, then the change, per logical step.

## 5. Review

1. Open the pull request as a draft.
   Its description is for someone with no context: the problem with a short example, the cause, the change, visible behavior, tests, known limits.
   State PHP's behavior plainly; do not cite php-src.
2. Run `pr-review` in a fresh agent.
3. Run a second fresh agent with the prompt below.

> The pull request claims `<fact>` now has exactly one owner: `<owner>`. Try to prove that false. Search all of `src/`, not just the changed files, for any code that still decides any part of the fact, directly or by re-deriving it from something it was handed. For each candidate, decide whether it decides this fact or a different one, and report both kinds separately with a concrete input. Do not edit files.

4. Verify every finding against the code.
   Fix findings in the pull request's own code fully, in the pull request.
   A finding in code the pull request did not touch becomes an issue linked from the pull request; with a reproduction, also a known-defect script (ADR 0008).
5. If a review finds a new case of a problem already fixed in this pull request, stop and report to the maintainer before patching again.
