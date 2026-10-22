# 0005: Syntax-invalid Document Recovery

Status: accepted

## Decision

Initial best-effort recovery of syntax-invalid documents (common-case: mid-edit editor buffers) is done by best-effort file/AST modification.
Fallback logic remains encapsulated within a document parsing composite (ADR 0003), and leverages its own composite for recovery techniques.

## Context

In support of ADR 0004 and the overall utility of the project, documents in various mid-edit state should produce ASTs as close to valid as possible so symbols can be extracted and downstream tools can continue to rely on those symbols.

Alternatives evaluated (October 2026):

- Closing open brackets, strings, and statements at the cursor with PHP's tokenizer, then parsing.
  Each failure needed another rule (control-structure headers, attributes, broken code earlier in the file), and it never became fully tolerant.
- `phan/tolerant-php-parser`.
  It recovers mid-edit code well, but rejects several PHP 8.3 to 8.5 constructs, and one rejection can drop the rest of a class.
- `tree-sitter-php`.
  It parses nearly all current syntax with errors that stay local, but needs FFI and a native library per platform.

This ADR _does not_ rule out supporting alternative parse paths in the future; it only provides context as to why they were not used upfront.
