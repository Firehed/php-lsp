# 0005: Syntax-invalid Document Recovery

Status: accepted

## Decision

Initial recovery of syntax-invalid documents (common-case: mid-edit editor buffers) is done by best-effort file/AST modification.
Fallback logic stays self-contained so that downstream consumers need no awareness of how recovery occurred and can confidently build on a well-formed AST (ADR 0004).
Other recovery approaches are allowed but out of scope for now; if implemented, they must still fit within these guidelines.

It is permissible for downstream consumers to be able to determine _if_ (but not how) a node came from recovery.
Consumers are only to use this to filter out synthesized code.

## Context

Documents in various mid-edit state should produce ASTs as close to valid as possible so symbols can be extracted and downstream tools can continue to rely on those symbols.
Various downstream consumers and handlers must not need to build their own fallback or recovery, as this leads to logic drift.
To be clear: this is largely restating ADR 0001; mid-edit file recovery is central to any LSP server and has historically been a cause of drift within this project.

Ideally, the design would not allow downstream consumers to know whether a node came from the recovery system at all.
Unfortunately, that is unavoidable: the recovery can synthesize code that doesn't exist, and some operations must filter this out.

Initially, the recovery approach is, in effect, surgical edits to the file that are likely to produce valid parses.

Alternatives evaluated (October 2026):

- Closing open brackets, strings, and statements at the cursor with PHP's tokenizer, then parsing.
  Each failure needed another rule (control-structure headers, attributes, broken code earlier in the file), and it never became fully tolerant.
- `phan/tolerant-php-parser`.
  It recovers mid-edit code well, but rejects several PHP 8.3 to 8.5 constructs, and one rejection can drop the rest of a class.
- `tree-sitter-php`.
  It parses nearly all current syntax with errors that stay local, but needs FFI and a native library per platform.

This ADR _does not_ rule out supporting alternative parse paths in the future; it only provides context as to why they were not used upfront.
