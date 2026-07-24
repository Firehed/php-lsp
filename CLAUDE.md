# CLAUDE.md

## Quick Start

```bash
composer test # PHPStan + tests + PHPCS (run before commits)
composer unit -- --filter X # Run specific tests
composer phpstan -- --error-format=raw --no-progress # run phpstan
composer phpstan -- --error-format=raw --no-progress path/to/analyze # run phpstan on a specific path
composer phpcs -- -q --report=emacs # run code style checks (PSR-12)
```

## Project Structure

- `src/Handler/` — LSP request handlers (completion, hover, definition, etc.)
- `src/Resolution/` — `CodeResolver`/`SymbolResolver` and the `Resolved*` symbol hierarchy (see Architecture below)
- `src/Repository/` — Class and member resolution (see Architecture below)
- `src/Domain/` — Domain objects representing code constructs
- `src/Index/` — Symbol indexing and workspace scanning
- `src/Document/` — Open document management
- `src/Parser/` — `ParserService` (the only place an AST is produced; memoizes by content for the duration of one handled LSP message, discarded by `Server`'s message loop) and `ParseMetrics` (parse count/time, which every parse is metered through)
- `src/Utility/` — AST helpers (ScopeFinder, Scope, TypeFactory, DocblockParser)
- `src/Completion/` — Completion context detection (`ContextDetector`, `CompletionClassifier`) and per-kind sources (`*Candidates`, `CompletionItemFactory`)
- `src/Capability/` — Protocol capability negotiation (see Capability Negotiation below)
- `docs/features/` — Feature status documentation
- `tests/Architecture/` — PHPStan rules enforcing RFC 1 §8.1 invariants, and their `RuleTestCase` tests
- `tests/Fixtures/` — Test fixture files (see Testing section)

## Architecture

### Resolution Layer

All symbol resolution flows through the `CodeResolver` interface (implemented by
`SymbolResolver`). Handlers depend on the interface, never on the concrete class.

**Point queries:**
- `resolveAtPosition(doc, line, char): ?ResolvedSymbol` — Definition, Hover, TypeDefinition

**Context queries:**
- `getMemberAccessContext(doc, line, char): ?MemberAccessContext` — Completion after `->`/`::`
- `getAccessibleMembers(doc, type, minVisibility, filter): list<ResolvedMember>` — members of a type
- `getVariablesInScope(doc, line, char): list<ResolvedVariable>` — Completion of `$`
- `getCallContext(doc, line, char): ?CallContext` — SignatureHelp, named-argument completion

**File queries** (parser-agnostic; keep completion sources off the raw AST):
- `getImports(doc): array<string, string>` — `use` imports as short name => FQCN
- `getFileFunctions(doc): list<FunctionInfo>` — user-defined functions declared in the document

**Type checks:**
- `isInstantiable(ClassName): bool` — valid after `new`
- `isValidTypeHint(ClassName): bool` — valid in a type-hint position (traits are not)

**`ResolvedSymbol` hierarchy** (`src/Resolution/`):
- `ResolvedSymbol` (base): `getDefinitionLocation()`, `getDocumentation()`, `getType()`, `format()`
- `ResolvedMember` extends `ResolvedSymbol`: `getDeclaringClass()`, `getName()`, `getVisibility()`, `isStatic()`
- `ResolvedCallable` extends `ResolvedSymbol`: `getParameters()`, `getReturnType()`, `getParameterAtPosition()`, `getParameterByName()`
- `ResolvedMethod` implements `ResolvedMember` + `ResolvedCallable`
- `ResolvedProperty`, `ResolvedConstant`, `ResolvedEnumCase` implement `ResolvedMember`
- `ResolvedFunction` implements `ResolvedCallable`
- `ResolvedClass`, `ResolvedVariable`, `ResolvedParameter` implement `ResolvedSymbol`

Incomplete code (e.g. `$this->`, `Foo::`) is handled inside `SymbolResolver` via
`TextFallbackHelper`, so handlers do not need their own fallbacks.

**Future (workspace queries):** references, implementations, sub/supertypes, call
hierarchy, and batch resolution. These require an index and will be added to
`CodeResolver` when those features are implemented.

### Namespace Catalog (Discovery)

Repositories and reflection answer *lookup* ("resolve this known name"). Completion
also needs *enumeration* ("what is inside `Psr\Log`?"), which is what the
`NamespaceCatalog` (`src/Index/`) provides: the child namespaces of a namespace, plus
the symbols declared directly in it.

- **WorkspaceNamespaceSource** — from `SymbolIndex`. The only source that must NOT be
  cached: the workspace changes with every keystroke.
- **ComposerNamespaceSource** — from Composer's autoload maps (`ComposerAutoloadMap`).
  PSR-4/PSR-0 map a namespace to a directory, so a namespace's contents are a directory
  listing, not a parse. `vendor/` is never pre-indexed; only namespaces actually visited
  are read.
- **ReflectionNamespaceSource** — the language's built-ins. Filter to `isInternal()` (the
  server's own classes are loaded in the same process), and file each symbol under the
  namespace its reflected name carries — **internal does not imply global** (`Random\Randomizer`).
- **CompositeNamespaceCatalog** merges and deduplicates; **CachedNamespaceCatalog** wraps
  only the stable sources.

Discovery reports a coarse `NameKind` (class-like / function / constant), not which
flavour of class-like: a PSR-4 listing cannot know without parsing. Deciding whether a
candidate is valid in a position stays with the `CodeResolver` predicates
(`isInterface`, `isThrowable`, …), which resolve through the caching `ClassRepository`.

Pair the catalog with `ReferenceResolver` (`src/Resolution/`), which computes the
shortest reference that resolves at the cursor. Discovery says what exists; resolution
says how to write it.

### Repository Pattern

Class and member information flows through a repository layer:

- **ClassRepository** (`DefaultClassRepository`) — Resolves `ClassInfo` by FQN. Resolution order: open documents → locate & parse from filesystem → reflection fallback for built-in classes.
- **MemberResolver** — Finds methods/properties on a class, traversing inheritance chain. Returns domain objects (`MethodInfo`, `PropertyInfo`).
- **ClassInfoFactory** (`DefaultClassInfoFactory`) — Creates `ClassInfo` from AST nodes or reflection.

### Domain Objects

Typed representations of code constructs in `src/Domain/`:

- `ClassInfo` — Class/interface/trait/enum metadata (methods, properties, constants, inheritance)
- `MethodInfo`, `PropertyInfo`, `ConstantInfo`, `EnumCaseInfo` — Member metadata
- `ParameterInfo`, `FunctionInfo` — Function/method parameter details
- `Visibility` enum — Public/protected/private with comparison logic
- `ClassName`, `MethodName`, `PropertyName` — Typed identifiers

Domain objects implement `Formattable` for consistent signature formatting across handlers.

### Type System

The `Type` interface represents PHP types throughout the codebase. Implementations:

- `ClassName` — Class/interface/trait/enum types (also serves as class identity)
- `PrimitiveType` — Built-in types (`string`, `int`, `bool`, `null`, `mixed`, etc.)
- `UnionType` — Union types (`A|B`); nullable types stored internally as `[A, null]` but format as `?A`
- `IntersectionType` — Intersection types (`A&B`)

Key methods:
- `format(): string` — Display representation (`?User` for nullable, `User|Admin` for unions)
- `getResolvableClassNames(): list<ClassName>` — Classes for member lookup (filters out primitives)
- `isNullable(): bool` — Whether the type includes null

**Never store types as strings.** Use `TypeFactory::fromNode()` or `TypeFactory::fromReflection()` to create Type objects at parse time. Use `Type::format()` only for display.

### Capability Negotiation

`src/Capability/` is the protocol-negotiation tier (RFC 1 §4.8, §5.4). It is the
**only** place the raw `initialize` parameters are read.

- **`CapabilityNegotiator`** owns the `initialize` exchange: it resolves the client's
  declared capabilities into a `SessionCapabilities` value, and returns the
  `InitializeResult` carrying the advertised `ServerCapabilities`. `LifecycleHandler`
  delegates to it and shapes nothing itself.
- **`SessionCapabilities`** is immutable and resolved once. Every capability the client
  did not declare resolves to the value's own default state — safe defaults live in the
  constructor, never in a branch at the point of use, so a minimal client needs no
  dedicated code path. It carries only already-resolved values and offers no way to
  build itself from a `Message`, so the raw parameters cannot be re-read through it —
  the confinement holds by construction, not merely by rule.
- **Advertised capabilities are a hand-maintained list** in `CapabilityNegotiator`.
  Add to it when a handler starts implementing a new LSP method; never advertise a
  capability the server does not implement.

Anything that shapes an outgoing message by client support (hover markup kind, snippet
support, …) queries `SessionCapabilities`. `RawInitializeCapabilitiesRule`
(`tests/Architecture/`) fails PHPStan if any other package reads a `capabilities` key.

### Transport and Lifecycle

`TransportInterface::read()` reports one of three outcomes, never a nullable message:
a `Message`, a `MalformedFrame` (carrying the `ResponseError` to answer with), or
`EndOfStream`. RFC 1 §9 requires a frame lacking a required header to be
distinguishable from a closed stream — one means answer and keep serving, the other
means stop. Do not collapse these back into `?Message`.

**Malformed input never terminates the process** (RFC 1 §9). `MessageReader`
classifies an unparseable body as `ParseError` and a structurally invalid message as
`InvalidRequest` — it does *not* rely on the `assert()`s in the message factories,
which are disabled in production. A message must carry `"jsonrpc":"2.0"` (JSON-RPC 2.0
§4); a frame missing it or naming another version is `InvalidRequest`.

A rejected frame is answered at whatever id the reader could recover from it, and at
the JSON-RPC null id only when it could recover none (JSON-RPC 2.0 §5) — answering a
recoverable id at null leaves the client's request pending forever. A frame that is
recognisably a *Notification* (a JSON object naming a method, with no `id`) is
consumed and dropped instead: §4.1 forbids replying to one, so `read()` skips it and
reports the next frame.

`Content-Length` must be a run of decimal digits (RFC 7230 §3.3.2, which LSP binds
via §3.2), and repeated headers must agree (§3.3.3). A bare `(int)` cast accepted `-5`,
which makes `substr()` consume from the wrong end. When the value is unusable the
frame's extent is unknown, so `read()` hands the rest of the buffer to the decoder
rather than rescanning it as the next header block: a content part is JSON, so a client
that merely mis-declared the length is served and anything else costs one `ParseError`.
Either way the buffer is emptied, so no failure path leaves bytes to be re-read as
framing. A conformant `Content-Length` still frames exactly, which is what tells a
truncated body from a complete one and separates two coalesced frames — the fallback is
the error path only.

`Server` answers a throwing handler with `InternalError` — including a failure in
`supports()` during handler lookup, and a result the encoder cannot represent, which
fails in the writer rather than in `handle()`.

`LifecycleHandler` owns lifecycle state and gates every inbound message through
`lifecycleErrorFor()` (RFC 1 §4.8): requests before `initialize` get
`ServerNotInitialized`, requests after `shutdown` get `InvalidRequest`, and `exit` is
always honored so the server can terminate. `initialize` "may only be sent once"
(LSP), so a second one is gated with `InvalidRequest` rather than re-negotiating over
the already-resolved session. A gated message is never dispatched; a gated
notification has no id, so its error is dropped rather than sent — which is what LSP
"Server lifecycle" means by notifications being *dropped*. The gate opens only once
`initialize` has produced a result.

`Server` takes the `LifecycleHandler` separately from the other handlers and
prepends it to the dispatch list itself, so the instance the gate consults cannot
diverge from the one that handles `initialize`/`shutdown`.

### Guidelines for New Code

- **Keep code DRY.** Be on the lookout for existing tools that will solve your problem; NEVER copy-and-paste. Extract repeated logic aggressively.
- **Use repositories, not direct reflection.** `MemberResolver::findMethod()` handles inheritance; raw `ReflectionClass` does not integrate with open documents.
- **Use domain objects.** Return `MethodInfo`/`PropertyInfo` from lookups, not raw AST nodes or reflection objects.
- **Add factory methods to domain objects** for new construction patterns (e.g., `FunctionInfo::fromNode()`, `FunctionInfo::fromReflection()`).
- **Check existing utilities before writing AST traversal.** Search `ScopeFinder` and handlers for similar patterns before creating new `NodeVisitorAbstract` implementations. Duplicate traversal logic should be extracted to utilities.
- **Use `ExpressionTypeResolver` for expression types.** It wraps `TypeResolverInterface` and handles special cases like `$this`. Use it consistently rather than calling `TypeResolverInterface` directly. Inside handlers, prefer `CodeResolver` (see Architecture Invariants) over calling this directly.
- **Handlers are formatters, not resolvers.** Handlers call `CodeResolver` and format the result. If you find yourself adding node detection, type resolution, or member lookup to a handler, STOP — add it to `SymbolResolver` instead. See Architecture Invariants.
- **Use `Type` objects, not strings.** Store and pass types as `Type` instances. Use `TypeFactory` to create them from AST or reflection. Call `format()` only at display time.
- **Do not use nullable types.** Null hides bugs and adds unnecessary conditionals.

### Architecture Invariants

Rules that MUST be followed. Violating these reintroduces the M×N handler×node bugs
described in #190, #253, and #256 (e.g. "hover works on X but definition doesn't").

**All symbol resolution goes through `CodeResolver`.**

Handlers do NOT:
- Parse documents, find nodes at positions, or detect node types
- Resolve types or look up members
- Call `MemberResolver`, `ClassRepository`, or `TypeResolverInterface` directly

Handlers DO:
- Extract LSP message parameters
- Call `CodeResolver` methods
- Format the result for their specific LSP response

`CompletionHandler` is a coordinator: it classifies the position and delegates to
completion *sources* (`src/Completion/*Candidates`), then merges and deduplicates.
It no longer parses documents or touches `ParserService`/`SymbolIndex` directly —
sources own their lookups, and anything parser-derived (imports, file functions,
members, variables, types) flows through `CodeResolver`. See Completion System.

**All type-graph traversal goes through `MemberResolver::supertypes()`.**

The type graph is walked in exactly ONE place. Every member lookup — methods,
properties, constants — follows the same edges (used traits, then the parent chain,
then interfaces), so no member kind can see a different hierarchy than another.

Six hand-written traversals is how #334 happened: only the constant lookups ever
learned to follow `interfaces`, so interface constants inherited while interface
methods did not, and every feature was wrong at once. Do NOT reintroduce a
per-member-kind walk. Adding an edge to the graph is a change to `supertypes()`.

`TypeGraphParityTest` enforces this: the members reported for a type must equal the
members PHP exposes at runtime (reflection is the oracle), across every shape —
extends, implements, interface-extends-interface, trait-using-trait, and interfaces
reached via a parent. A traversal that misses an edge fails it.

**All client-capability reads go through `SessionCapabilities`.**

The raw `initialize` parameters are read once, in `src/Capability/`. No other package
may re-inspect them; output shaped by client support queries `SessionCapabilities`
instead. `RawInitializeCapabilitiesRule` enforces this in PHPStan (RFC 1 §4.8, §8.1).

**Adding support for a new AST node type:**
1. Add handling in `SymbolResolver` (ONE place)
2. Create a `ResolvedX` implementation if needed
3. All handlers support it automatically
4. Write tests in `SymbolResolverTest`

**Adding a new LSP handler:**
1. Create the handler with `DocumentManager` + `CodeResolver` dependencies
2. Call the appropriate `CodeResolver` method
3. Format the result for the LSP response
4. Do NOT add resolution logic to the handler

### Utility Classes

- `ScopeFinder` — Finds enclosing class/method scope in AST, resolves names, finds functions
- `Scope` — Value object modelling a lexical scope (params, statements, self/parent context, `$this`, closure captures). Function-like nodes and file-level/global code both map onto it via `Scope::atOffset()`/`forNode()`/`global()`, so type/variable resolution never branches on node type or handles a "no enclosing function" case.
- `DocblockParser` — Extracts description from docblocks
- `ExpressionTypeResolver` — Resolves expression types (wraps TypeResolverInterface, handles `$this`)
- `TypeFactory` — Creates Type domain objects from AST nodes and reflection

Note: `MemberAccessResolver` was removed in #262 — instance/static member access now flows through `SymbolResolver`.

## Development Workflow

- GitHub issues are the source of truth for feature specs
  - Before starting on a feature, verify that it hasn't already been impemented
  - Resolve any ambiguity or conflict BEFORE starting to write a line of code
- Update `docs/features/*.md` when merging features
- Run `composer test` before commits
- `composer.lock` is gitignored — do not attempt to stage or commit it
- Debugging: use the testing framework to debug code paths. DO NOT write arbitrary PHP scripts.

## Completion System

See `docs/features/completion.md` for current capabilities.

Architecture (`CompletionHandler` is a coordinator, not a resolver):

1. **Coarse gate** — `ContextDetector` (token-based) classifies the broad context
   (None / VariablesOnly / Full); token analysis survives unparseable code.
2. **Member/static/call** — detected via `CodeResolver` (`MemberCandidates`,
   `getCallContext`), which is AST-first with a text fallback (`TextFallbackHelper`).
3. **Everything else** — `CompletionClassifier` maps the text before the cursor to a
   typed `CompletionKind`; the handler dispatches to a source per kind.

**Completion sources** (`src/Completion/*Candidates`) each own one candidate kind
(classes, functions, keywords, variables, members, named arguments, builtin types):
lookup + prefix filter + item construction (via `CompletionItemFactory`). Adding a
completion kind = a new source + a `CompletionKind`/enum case, not handler edits.
`ClassCandidates` is filtered by intent (`ClassCandidateFilter`); the mapping is the
extension point for context-specific class filtering (e.g. `implements` → interfaces,
issue #298).

**Detection stays text-based where it is the mid-edit resilience layer.** This is a live
server: completion must keep working on temporarily-broken code (see
`CompletionHandlerTest::testCompletionThisInVeryBrokenFile`, where the parser yields no
AST). `CompletionClassifier` and `ContextDetector` are deliberately text/token-based —
do **not** convert them to AST analysis. Only member/static/call access flow through the
AST+fallback `CodeResolver` path.

## Testing

### Writing Tests

Do not re-invent AST traversal. It is built in to the library. You probably want an existing utility in the project, or `PhpParser\NodeFinder`.

Do not write new tests using inlined PHP code. ALWAYS use the fixture tooling when the test is covering code or file handling.

### Test Fixtures

Handler tests use fixture files in `tests/Fixtures/` instead of inline PHP code. Fixtures are a nested Composer project with their own dependencies and autoloading — run `composer install` in `tests/Fixtures/` before running the suite, and again after adding files outside of the PSR-4 or PSR-0 paths.

Re-use existing fixtures whenever possible. Prefer adapting or expanding existing fixtures over adding brand new ones.

Structure (all under `tests/Fixtures/src/` with `Fixtures\` namespace):

- `Domain/` — Core domain model: User, Entity
- `Enum/` — Enum fixtures: Status, Priority, Color
- `Traits/`, `Inheritance/`, `Services/` — OOP patterns
- `Repository/` — Repository pattern examples
- `Completion/`, `Hover/`, `Definition/`, `SignatureHelp/` — Handler-specific fixtures with cursor markers
- `TypeInference/` — Type resolver test fixtures
- `Legacy/` — Code quality variations (docblock-only, untyped)
- `Mixed/` — Procedural + OOP mixes

Non-PSR-4 fixtures (outside `src/`):
- `Autoload/Psr0/` — PSR-0 style classes
- `Autoload/Classmap/` — Classmap-loaded classes
- `MultiClass/` — Multi-class file scenarios
- `Namespacing/` — Namespace syntax variations

### Fixture Guidelines

**Reuse existing fixtures.** Before creating new classes/enums, check if `Domain/`, `Enum/`, `Inheritance/`, etc. already have what you need. Import and extend them:

```php
use Fixtures\Inheritance\ChildClass;
use Fixtures\Enum\Status;

class MyCompletionTest extends ChildClass { ... }
```

**One class per file (PSR-4).** Each `.php` file in `src/` must contain exactly one class matching the filename. Multiple classes in one file breaks autoloading.

**Multiple markers in one class.** Put related cursor markers in different methods of the same class, not separate files:

```php
class InheritanceCompletion extends ChildClass
{
    public function triggerThis(): void { $this->/*|this_inherited*/ }
    public function triggerSelf(): void { self::/*|self_inherited*/ }
    public function triggerParent(): void { parent::/*|parent_access*/ }
}
```

**Domain objects go in domain directories.** New enums → `Enum/`, new classes → `Domain/`, etc. Don't duplicate domain concepts in handler-specific directories.

**Non-autoloaded fixtures go outside `src/`.** Files that intentionally violate PSR-4 (multi-class files, namespace syntax tests) belong in top-level directories like `MultiClass/`, not in `src/`.

### Fixture Helpers

`OpensDocumentsTrait` provides helpers for handler tests:

```php
// Open a fixture file
$uri = $this->openFixture('src/Domain/User.php');

// Open fixture and get cursor position from marker
$cursor = $this->openFixtureAtCursor('src/Completion/MethodAccess.php', 'this_empty');

// Build request from cursor position
$result = $this->handler->handle($this->completionRequestAt($cursor));
```

`LoadsFixturesTrait` provides fixture loading for unit tests (no handler infrastructure):

```php
// Load fixture content and parse
$content = $this->loadFixture('src/TypeInference/NewKeywords.php');
$ast = $this->parse($content);
```

### Cursor Markers

Two marker conventions exist for different test scenarios:

**`/*|marker*/` — cursor BEFORE marker (incomplete expressions)**

For completion and signature help where the cursor is mid-expression:

```php
public function triggerCompletion(): void
{
    $this->/*|method_access*/
}
```

`openFixtureAtCursor()` returns the position immediately before the marker. Use for incomplete statements that need parser error recovery.

**`//hover:marker` — cursor ON symbol (complete expressions)**

For hover tests where the cursor must be on an existing symbol:

```php
$user->getName(); //hover:method_call
```

`openFixtureAtHoverMarker()` finds the line and positions the cursor on the last member access or function call. Use for complete, parseable statements.

**Which to use:**
- Incomplete code (`$this->`) → `/*|marker*/`
- Complete code (`$this->method()`) → `//hover:marker`

**Limitation:** Each incomplete statement needs its own method. Multiple incomplete statements in one method confuse parser error recovery:

```php
// Works - separate methods
public function a(): void { $this->/*|a*/ }
public function b(): void { $this->/*|b*/ }

// Broken - parser fails
public function bad(): void {
    $this->/*|a*/
    $this->/*|b*/
}
```

### Multi-file Tests

For go-to-definition and similar tests needing multiple files:

```php
$defUri = $this->openFixture('Definition/MyClass.php');
$cursor = $this->openFixtureAtCursor('Definition/usage.php', 'on_class');
$result = $this->handler->handle($this->definitionRequestAt($cursor));
self::assertSame($defUri, $result['uri']);
```

### Shared Fixtures

Fixtures in `src/Domain/`, `src/Inheritance/`, etc. are shared across tests. Rules:
- **Additive changes OK:** Adding methods, properties, classes
- **Breaking changes require coordination:** Don't rename, remove, or change signatures

## LSP Protocol

Server communicates over stdio. Test with any LSP client; `docs/vim-ale.md` has Vim setup notes.

## Project Guidelines

- Aggressively, proactively refactor. Consistent behavior is paramount to long-term success.
- ALWAYS follow TDD.
- Test coverage MUST be 100% for all new code. NO EXCEPTIONS. If a branch should be unreachable, it should either be rewritten to be eliminated or, if impractical, throw a logic exception and marked for coverage ignore. Prefer to eliminate the dead branch.
- Update documentation and guidelines when making changes. It is critical to keep this up to date to avoid drift and redundant work.
