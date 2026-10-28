# 3: Composite Services

Status: accepted

## Decision

If there is more than one implementation of a service, logic for which one to use and/or how to combine their results is to be done in a Composite service.

A composite is the **only** place outside of implementations that holds switching or combining logic of which implemention to apply.
It is the **only** place outside of the DI container that directly names concrete implementations, **must** do so in its constructor, and **must** name all of the concrete implementations directly.
There MUST be more than one concrete implementation named by the composite, therefore a composite **must not** be created until multiple implementations exist.

It is permissible to apply the decorator pattern to any implementation inclduing a composite; this should also be done in the container.

## Context

Generally, this is the Strategy and Chain of Responsibility design patterns applied to this codebase.

Multiple implementations generally exist to supplement each other, not compete.
For example, a "file contents service" may have implementations for data on-disk and in an editor's open buffers.
If multiple places named one of these services directly, it would likely lead to bugs where e.g. one handler correctly knows about editor buffers and another only understands data on disk.
Composites exist to centralize this logic; by implementing the same interface and combining the implementations, consumers of this knowledge continue to only see the interface for this service and don't need to know or care how or from where the data was read.

While technically there could be a single implementation handling both cases, this is a poor design since it violates the single-responsibility principle.

### Litmus Test

Adding tree-sitter as a source code parser touches two source files: the new tool class, and the composite that wires the parsing tools together.
A completion handler cannot tell how the AST was parsed.
A change that needs more files than that has found a second route to a fact; this is an architecture bug.
