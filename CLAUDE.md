# CLAUDE.md

## Quick Start

```bash
composer test # PHPStan + deptrac + tests + PHPCS (run before commits)
composer unit -- --filter X # Run specific tests
composer phpstan -- --error-format=raw --no-progress # run phpstan
composer phpstan -- --error-format=raw --no-progress path/to/analyze # run phpstan on a specific path
composer phpcs -- -q --report=emacs # run code style checks (PSR-12)
```

## Design

The project is a language server for PHP.
Every design rule below exists to stop one problem: multiple routes to one fact.
Routes drift, and the drift shows up as a feature that works in hover but not in completion, or a member kind that inherits while another does not.

The codebase is undergoing structural transition to eliminate this issue.
This document's guidance should override matching existing structure.

### Services and data types

A data type is a `readonly` structure over one or more values.
It carries no logic beyond formatting and simple checks.

Application-wide data types live in `src/Domain/`.
A data type that only one service uses lives next to that service, and should not be referenced outside of that service.

A service produces or exposes facts: which symbols a file declares, which file defines a symbol, what type an expression has.
Every service is used through an interface, even when it has one implementation.
The interface describes what a caller can ask.
It never says where the answer comes from, and it never takes a parser node or a reflection object where another implementation would take something else.

### One wiring place

Instantiating services is to occur in one wiring place: a PSR-11 container.

The `::forProject` static methods that exist are legacy and not the standard.
However, until PSR-11 conversion is complete, it may be necessary to build on it.

### Interfaces, Implementations, and Composites

Where services rely on other services, the references are to be named by interface only, never an implementation.

Concrete implementations may be referenced in exactly two places:
1) the interface-to-implementation mapping in the PSR-11 container
2) the constructor arguments for a composite service.

A composite is defined as an implementation of an interface that wires together two or more other implementations of the same interface.

A composite MUST be added once an interface gains a second implementation and MUST be the only place that contains logic of how to switch between the implementations.
Stated directly: there should never be only two implementations of an interface; there's a single one wired directly, or 2+ functional implementations with a composite with the composite wired.
Composites SHOULD name the other concrete classes as their constructor arguments.

Note: applying decorators to the wired class (example: caching) is still permitted, and this should also be done in the PSR-11 wiring.

### Factories

A factory must justify its existence.
Most do not.
Construction logic that several methods share is a private method on the service.
Construction logic that several implementations of one family share is a trait in that family.

### Handlers

An LSP handler extracts parameters, calls a resolver interface, and formats the result.
A handler never parses a document, walks a tree, resolves a type, or looks up a member.
If a handler is about to do one of those, the logic belongs in a resolver implementation.

### The litmus test

Adding tree-sitter as a parser touches two files: the new tool class, and the composite that wires the parsing tools together.
A completion handler cannot tell whether a tree came from tree-sitter, php-parser, or the text skeleton.
A change that needs more files than that has found a second route to a fact.

### Type graph

Every question about the type graph goes to the member resolver, member lookup and subclass checks alike.
Its implementation walks used traits, then the parent chain, then interfaces.
No other class walks the graph, so no member kind can see a different hierarchy than another.
`TypeGraphParityTest` checks the reported members against PHP's runtime reflection.

### Client capabilities

The raw `initialize` parameters are read once, in `src/Capability/`.
Every decision that shapes output by client support reads `SessionCapabilities`.

### Completion detection stays text-based

`ContextDetector` and `CompletionClassifier` read text and tokens on purpose.
Completion must keep working on code that does not parse mid-edit.
Do not convert them to tree analysis.


### Caching

A service may hold an index it derives from its whole source and rebuilds on invalidation.
A service never remembers the answer to a query.
Answers are remembered by one PSR-16 decorator per backend, applied in wiring, never around open documents.

#### Invalidation

The server never decides on its own that an answer is stale.
The editor is the only source of change events.
Two events invalidate: `workspace/didChangeWatchedFiles` for a path, and closing a document that was open.
Both flow through `SymbolSinkInterface::invalidate`, which fans out to every invalidatable in the wiring.
An open buffer is not an invalidation; it wins by composite order while it is open.
Built-ins are never invalidated until the target environment can change.

## Handling Design or Specification Tensions

This codebase is in flux, and the current state may not reflect the intended design.
Issues may have been written prior to the intended structure, and may be in tension with the goal.
If you encounter this scenario, stop and ask the human.

## Enforcement tools

PHPStan rules under `tests/Architecture/`, the allowlists in `phpstan.neon`, and the layer ruleset in `deptrac.yaml` were written before the design above settled.
Some of them cement the old shape.
When one fires against a change that follows the design above, stop and ask the human.
Do not route around the rule, and do not bend the design to satisfy it.
Never edit a rule, an allowlist, a baseline, or `bin/check-baseline-shrink` yourself.
`docs/architecture/enforcement-edits.md` classifies every such edit.

When considering edits (or avoiding them), remember: the project and architecture goals are more important.

## Development Workflow

- GitHub issues are the source of truth for feature specs. Confirm a feature is not already built, and resolve every open question before writing code.
- A good issue states which service owns each fact and how the work separates concerns. Adding a feature is adding a value on an existing axis, not adding a route.
- Follow TDD. Coverage must be 100% for new code. Rewrite a dead branch out instead of excluding it.
- Update `docs/features/*.md` when merging features.
- Run `composer test` before commits.
- `composer.lock` is gitignored. Do not stage or commit it.
- Debug through the test suite. Do not write ad hoc PHP scripts.
- When creating a new issue, add the `ai-authored` label.

## Testing

Do not write tests with inline PHP code.
Use the fixture tooling when a test covers code or file handling.

### Fixtures

Fixtures live under `tests/Fixtures/` as a nested Composer project.
Run `composer install` there before the suite, and again after adding files outside the PSR-4 or PSR-0 paths.

Reuse existing fixtures before adding new ones.
Shared domain fixtures live in `src/Domain/`, `src/Enum/`, `src/Inheritance/`, and similar; import and extend them.
Handler-specific fixtures with cursor markers live in `src/Completion/`, `src/Hover/`, `src/Definition/`, and `src/SignatureHelp/`.
Files that intentionally break PSR-4, such as multi-class files, live in top-level directories like `MultiClass/`, not under `src/`.

Additive changes to shared fixtures are fine.
Renames, removals, and signature changes need coordination.
Some fixtures back the parity goldens under `tests/Parity/`; any change to one changes its golden.
Recapture with `UPDATE_GOLDENS=1` and review the diff; see `tests/Parity/README.md`.

### Helpers

`OpensDocumentsTrait` serves handler tests:

```php
$uri = $this->openFixture('src/Domain/User.php');
$cursor = $this->openFixtureAtCursor('src/Completion/MethodAccess.php', 'this_empty');
$result = $this->handler->handle($this->completionRequestAt($cursor));
```

`LoadsFixturesTrait` serves unit tests with no handler infrastructure:

```php
$content = $this->loadFixture('src/TypeInference/NewKeywords.php');
```

### Cursor markers

`/*|marker*/` puts the cursor before the marker.
Use it for incomplete code such as `$this->`, where the parser must recover.
Each incomplete statement needs its own method; two in one method confuse parser recovery.

`//hover:marker` puts the cursor on the last member access or function call on that line.
Use it for complete code such as `$this->method()`.

## LSP Protocol

The server communicates over stdio.
`docs/vim-ale.md` has Vim setup notes.

## Code Style Guidance

PHPCS enforces PSR-12 at minimum; generally follow the latest "PER" style guide as allowed by the minimum PHP version defined in composer.json.

Leverage modern PHP features where appropriate and useful:
- Asymmetric visibility
- Interface properties
- Property hooks
- Attributes
- Named arguments

The above list is non-exhaustive. `composer.json` lists the canonical minimum version (currently 8.4+).
