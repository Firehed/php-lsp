# 0004: One Structure per Document

Status: proposed

## Decision

A document's one structure is php-parser's syntax tree, and every feature reads code through it.
Code being edited usually does not parse; it is recovered into that same tree, in the shape it will have once completed, rather than read some other way.
Valid code and code being edited are two states of the same fact, so they take the same route.

Recovery happens where the tree is produced, before any feature sees it.
It reuses php-parser to finish, wrap, and re-read code, and grafts what it reads into the tree; it never reimplements PHP's grammar.
It is made of independent steps, each handling one kind of unreadable code, so a new case means adding or extending a step rather than changing features.
What recovery supplies, rather than reads from the document, is distinguishable from code someone wrote.

Features do not walk the tree or read the document's text to learn what is at a position.
They ask the services that answer that question, and one place reads the structure for them.

## Context

Features used to read broken code their own way: some through the tree, some through text patterns before the cursor, some by rebuilding nodes from text.
The same position could mean different things to hover, completion, and signature help, and a fix in one route never reached the others.

Alternatives evaluated (October 2026):

- Closing open brackets, strings, and statements at the cursor with PHP's tokenizer, then parsing.
  Each failure needed another rule (control-structure headers, attributes, broken code earlier in the file), and it never became fully tolerant.
- `phan/tolerant-php-parser`.
  It recovers mid-edit code well, but rejects several PHP 8.3 to 8.5 constructs, and one rejection can drop the rest of a class.
- `tree-sitter-php`.
  It parses nearly all current syntax with errors that stay local, but needs FFI and a native library per platform.

Staying on php-parser keeps full support for current PHP and the node types the codebase already uses.
Recovery cannot be perfect without writing a parser, which is out of scope; it only has to handle the common cases and fail without affecting anything outside the step that failed.
