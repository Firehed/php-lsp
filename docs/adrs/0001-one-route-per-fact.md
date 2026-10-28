# 1: One Route Per Fact

Status: accepted

## Decision

There must be only one route in the codebase to reach any given fact.

Before writing code that answers a question (e.g. what a symbol is, where it is declared, what type an expression has), check whether the codebase already answers it.
If it does, use that answer.
If the answer is incorrect or incomplete, file a bug and/or fix it; do not patch around it downstream.
Write tests as if the data provider was correct; if necessary, mark it as skipped due to the issue you filed.

## Context

Early versions of this codebase suffered from duplicated logic to get equivalent information (sometimes referred to in issues and commits as an MxN problem).
As an example, multiple places would read file contents, parse their AST, and extract symbol information in order to provide data to the LSP handlers.
When a bug was discovered, the fix would not get applied in all contexts, leading to behavioral drift.

Patching around a bug rather than fixing the root cause also leads to drift.
