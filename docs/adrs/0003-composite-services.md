# 0003: Composite Services

Status: accepted

## Decision

If there is more than one implementation of a service, logic for which one to use and/or how to combine their results is to be done in a Composite service.

There are **never** to be exactly two implementations of an interface.
Either there is a single implementation (wired directly in the container), or there are multiple implementations and a composite to dispatch the logic.
A composite is not added proactively when there is a single implementation.

A composite is the **only** place outside of implementations that holds switching or combining logic.
It is the only place that directly names concrete implementations, and **must** do so in its constructor.

Per ADR 0002, the composite should be set up in the DI container.
It is permissible to apply the decorator pattern to a composite; this should also be done in the container.

## Context

Generally, this is the Strategy and Chain of Responsibility design patterns applied to this codebase.

Multiple implementations generally exist to supplement each other, not compete.
For example, a "file contents service" may have implementations for data on-disk and in an editor's open buffers.
If multiple places named one of these services directly, it would likely lead to bugs where e.g. one handler correctly knows about editor buffers and another only understands data on disk.
Composites exist to centralize this logic; by implementing the same interface and combining the implementations, consumers of this knowledge continue to only see the interface for this service and don't need to know or care how or from where the data was read.

While technically there could be a single implementation handling both cases, this is a poor design since it violates the single-responsibility principle.
