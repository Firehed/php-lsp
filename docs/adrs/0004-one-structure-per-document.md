# 0004: One Structure per Document

Status: proposed

## Decision

Every feature reads a document through one structure: the tree the syntax source produces, recovered when the code does not parse.
Valid code and code being edited are two states of the same fact, so they take the same route.

### Recovery

Recovery happens inside the syntax source, before anything else sees the tree.
It is a sequence of independent steps behind one interface, combined by a composite (ADR 0003).
Each step handles one kind of unreadable code and is tested on its own; handling more cases means adding or extending a step, not touching consumers.
Recovery reuses php-parser: it finishes, wraps, and re-reads code, and grafts what it reads into the tree.
It never reimplements the grammar.

A node recovery supplies that is not written in the document takes no space: it ends before it starts.
`ParsedDocument::isWritten()` is the one test for it, so a stand-in never reads as code someone wrote.

### Positions

One locator reads the structure at a position and is the only code that dispatches on parser node classes for it.
What it reads stays inside the position services, which answer the questions features ask: the call holding a position, what is in scope there, the symbol a word names, and what may be written there.
Handlers and completion sources ask those services and never walk the tree.

The locator reads the document's text for three things only: the word written before the position, whether only whitespace and comments separate it from an empty slot, and the punctuation of a declaration's header.
Anything else it needs comes from the structure.

## Context

Features used to read broken code their own way: some through the tree, some through text patterns before the cursor, some through a locator that rebuilt nodes from text.
The same position could mean different things to hover, completion, and signature help, and a fix in one route never reached the others.
Recovery cannot be perfect without writing a parser, which is out of scope; it only has to handle the common cases and fail without affecting anything outside the step that failed.
