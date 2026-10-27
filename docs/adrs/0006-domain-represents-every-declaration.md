# 0006: The Domain Represents Every Declaration

Status: accepted, supplements 0001 and 0004.

## Decision

Everything PHP lets code declare has a domain type, along with every part of it: functions, methods, closures, and arrow functions with their parameters and return types; classes, including anonymous ones; properties, including hooks and asymmetric visibility.

Domain types are built only where declarations are read, from parsed source or from PHP's reflection.
Everything else reads the domain types, never the declaration's syntax tree.

A declaration, or part of one, that the domain cannot represent is a defect in the domain.
Work that runs into one either extends the domain and the code that builds it, or stops and asks.
A local workaround is not acceptable, and neither is a comment deferring the fix.

## Context

ADR 0001 forbids a second route to a fact, but a gap in the domain leaves no first route to compare against.
Code that needed a closure's parameter types read them from the syntax tree, because the domain could not represent a closure.
Each such reader is a route of its own, and they drift: closures, property hooks, and anonymous classes ended up handled differently from their named counterparts, or not at all ([#867](https://github.com/Firehed/php-lsp/issues/867)).

Code that reads declaration syntax outside the places that build domain types is the problem this decision fixes, not a pattern to follow.
