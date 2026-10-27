# 0006: The Domain Holds Facts

Status: accepted

## Decision

Everything PHP lets code declare has a domain type, along with every part of it.
A domain type is the same whether it was built from source (as-written or recovered) or from reflection; nothing that reads it can tell where it came from.

A declaration, or part of one, that the domain cannot represent is a gap in the domain.
The fix is to extend the domain, preferably in a change that lands before the work that needs it.
A local workaround is not a fix, and neither is a comment deferring one.

## Context

ADR 0001 forbids a second route to a fact, but a gap in the domain leaves no first route to compare against.
The gap shows up as missing structure rather than as a duplicate route, so it is easy to work around without noticing.
One structure per declaration, regardless of origin, is what lets every feature see the same facts.
