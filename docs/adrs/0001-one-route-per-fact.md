# 0001: One Route Per Fact

Status: accepted

## Decision

There must be only one route in the codebase to reach any given fact.

## Context

Early versions of this codebase suffered from duplicated logic to get equivalent information (sometimes referred to in issues and commits as an MxN problem).
As an example, multiple places would read file contents, parse their AST, and extract symbol information in order to provide data to the LSP handlers.
When a bug was discovered, the fix would not get applied in all contexts, leading to behavioral drift.
