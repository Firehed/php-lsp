# 7: Syntax Produces Facts

Status: accepted, supplements 4.

## Decision

One walker reads the syntax tree, parsed or recovered, and turns it into facts: domain types for declarations, what sits at a position, and facts about code inside bodies such as expression types and variable bindings.
Nothing else walks the tree or reads facts from syntax nodes.
Everything else in the server consumes the facts the walker produces.

## Context

Every walker is its own interpretation of the same tree, and separate interpretations drift.
Code that needed a closure's parameter types read them from the syntax tree because the domain could not represent a closure; closures, property hooks, and anonymous classes ended up handled differently from their named counterparts, or not at all.
Recovered trees can make this worse: each walker tolerates broken code differently, so a feature can work on half-typed code while another does not.
Note: walkers must not attempt to work around broken code themselves; that's an issue with the recovery tool.

ADR 4 requires walking the tree to be centralized; this decision is how.
