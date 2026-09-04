# CLAUDE.md

## Quick Start

```bash
composer test # PHPStan + tests + PHPCS (run before commits)
composer unit -- --filter X # Run specific tests
composer phpstan -- --error-format=raw --no-progress # run phpstan
composer phpstan -- --error-format=raw --no-progress path/to/analyze # run phpstan on a specific path
composer phpcs -- -q --report=emacs # run code style checks (PSR-12)
```

## Guardrails (default-deny)

CI-enforced mechanisms confine where code may live; a rule firing on your change is design feedback, not an obstacle.

- **Capability confinement** (`phpstan.neon`): parsing and lexing, AST traversal, symbol-name case folding, regex, runtime reflection, runtime symbol existence/enumeration/kind inspection, and filesystem access are each usable only in their named homes (allowlists inline, each with its rationale). A deny set names every spelling of its capability, aliases included, so do not reach for a synonym.
- **Layer contract** (`deptrac.yaml`): an inter-layer dependency not in the ruleset fails analysis. A class in no layer is not analysed at all, so `composer layer-coverage` fails when `deptrac debug:unassigned` lists one.
- **Kind and type rules** (`tests/Architecture/*Rule.php`): no `new` of a `Type` implementation outside `TypeFactory`; no `instanceof` against a concrete `Type` or `ResolvedSymbol`; no branch on a kind enum outside its named homes, in any form (`match`, `switch`, the four equality operators, `in_array`/`array_search`, or the same comparison against `->value` or `->name`).
- **Literal class references** (`DynamicClassReferenceRule`): a class is named literally. `new $c`, `$v instanceof $c`, `$v::class`, `$c::CONST` and `$c::m()` are denied, because every rule above reads a name to apply. Use `$v::class` nowhere; reach for a predicate instead.
- **File inclusion** (`FileInclusionRule`): `include`/`require` read the disk, which no call list can name, so they are confined like the filesystem functions.
- **Self-check** (`ConfinementCoverageTest`, `EnforcementWiringTest`, `OneRoutePerFactTest`): every `Type` and `ResolvedSymbol` implementation is in its rule's list, every enum is confined or registered as not a kind, every rule is registered with PHPStan and has its own test, every allowlisted path still exists, and every implementation of a one-route interface is named only by its composition root (see One route per fact under Architecture Invariants).

When a rule fires on your change:

1. Move the logic into (or route it through) the confined authority — the usual fix.
2. If the authority genuinely cannot serve the need, extend the authority.

There is no third option. Widening an allowlist, adding a layer edge, or growing a baseline for an existing check is a **Loosen** edit, and only the human makes one.
`docs/architecture/enforcement-edits.md` classifies every edit to a rule, allowlist, baseline, or policy file as Tighten, Lateral, or Loosen; read it before touching any of them.
A PR body cannot justify a Loosen edit.

NEVER regenerate a baseline to absorb a new violation.
The baselines (`phpstan-baseline.neon`, `deptrac.baseline.yaml`) freeze pre-existing debt only and must shrink to zero; CI enforces shrink-only (`bin/check-baseline-shrink`).
Regenerate (`composer phpstan-baseline` / `composer deptrac-baseline`) only when draining entries, when a refactor moves a file whose violations the baseline already froze (a Lateral edit), or when a newly added check reports pre-existing violations (a Tighten edit).
When changing a file that has baseline entries, prefer draining them in the same change.
This section overrides the global "avoid adding to the baseline" guidance in the strict direction: here, additions are forbidden outright and shrink-churn is the goal.

## Project Structure

- `src/Handler/` — LSP request handlers (completion, hover, definition, etc.)
- `src/Resolution/` — `CodeResolver`/`SymbolResolver` and the `Resolved*` symbol hierarchy (see Architecture below)
- `src/Repository/` — Class and member resolution (see Architecture below)
- `src/Domain/` — Domain objects representing code constructs
- `src/Index/` — Symbol indexing and workspace scanning
- `src/Document/` — Open document management
- `src/Parser/` — `ParserService` (the only place an AST is produced; memoizes by content for the duration of one handled LSP message, discarded by `Server`'s message loop) and `ParseMetrics` (parse count/time, which every parse is metered through)
- `src/Utility/` — AST helpers (ScopeFinder, Scope, DocblockParser)
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
- `getNameContext(doc, line): NameContext` — the namespace and the three import tables in effect
- `getFileFunctions(doc): list<FunctionInfo>` — user-defined functions declared in the document, at any depth

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
- **AutoloadFilesLocator** — the `autoload.files` set, which sits outside every PSR-4 and
  PSR-0 prefix, so no directory listing reaches it. It enumerates the index it already
  derived for lookup, reporting each declaration's own `NameKind` rather than a guess.
Each source is wrapped as a `SymbolBackend` (see Symbol Backends below): the
`CompositeSymbolSource` merges and deduplicates their `childrenOf` results, and
**CachedNamespaceCatalog** wraps the stable sources (workspace-on-disk, vendor,
built-in) — the open-document `WorkspaceNamespaceSource` is never cached.

Discovery reports a coarse `NameKind` (class-like / function / constant), not which
flavour of class-like: a PSR-4 listing cannot know without parsing. Deciding whether a
candidate is valid in a position stays with the `CodeResolver` predicates
(`isInterface`, `isThrowable`, …), which resolve through the `SymbolSource` backends.

Pair the catalog with `ReferenceResolver` (`src/Resolution/`), which computes the
shortest reference that resolves at the cursor. Discovery says what exists; resolution
says how to write it.

### Symbol Backends

Class-like lookup, function lookup, namespace enumeration, and class-like prefix search
flow through the **`SymbolSource`** read seam (`src/Knowledge/`), implemented by
**`CompositeSymbolSource`** over a fixed-precedence list of **`SymbolBackend`s**
(RFC 1 §5.3):

1. **`OpenDocumentBackend`** — the editor's open documents (never cached); its answer overrides the rest.
2. **`FilesystemBackend`** (workspace) — the project's own on-disk code, resolved via Composer's non-`vendor/` autoload prefixes: locate one file and parse it.
3. **`FilesystemBackend`** (vendor) — installed dependencies, the `vendor/` autoload prefixes. Same class as workspace, a different autoload-map subset (`ComposerAutoloadMap::partitionByVendorDirectory`).
4. **`BuiltinBackend`** — PHP built-ins and loaded extensions, via reflection (a tracked §4.7 gap: it describes the *server* runtime, not the target).

A lookup takes the first backend that answers; enumeration and search merge every
backend, the earlier (more authoritative) one winning a name clash. Caching is a
per-backend PSR-16 policy (`src/Cache/`); on-disk and built-in results are cached, open
documents never. A cache key carries the `NameKind` (`SymbolCache`): PHP's three
symbol namespaces are independent, so a class and a function may share a name.

Lookup is **per-kind at the `SymbolSource` facade** — a typed method per kind, taking a
name type that carries its kind (`ClassName`, `FunctionName`), because RFC 1 §5.1 requires
a concrete return type rather than a type-erased union — and **kind-parameterized at
`SymbolBackend`**: one `lookup(QualifiedName, NameKind): ?SymbolInfo`. Do NOT read the
facade's closed method set as licence to add a per-kind backend method. Kind dispatch
lives in `DeclarationSymbolInfoFactory` and `ReflectionSymbolInfoFactory`, one per
metadata route, so a new kind is a case in each rather than a method on every backend.
`SymbolCoverageGridTest` enforces §5.1 with a backend × kind × query grid whose backend
and kind axes are derived: every cell answers or names its blocker, and an unregistered
cell fails.
`lookupFunction` reaches
open documents, the `autoload.files` set, and PHP's built-ins — the last filtered to
`isInternal()`, because reflection also sees the functions the *server's* own
dependencies declare, which are not the project's. A function in an unopened PSR-4 file
has no name→file route at all: Composer's maps address class-likes only, which is
RFC 1 §3's locate-only limitation, not a backend gap.

Each `FilesystemBackend` finds its file through a **`CompositeSymbolLocator`** over
two locators, cheapest first: `ComposerSymbolLocator` (PSR-4/PSR-0/classmap — arithmetic
on the name) and `AutoloadFilesLocator`. The latter exists because `autoload.files`
entries are addressed by *no* name at all, so the only route is to parse the set and
derive the map; it is built eagerly, covers all three symbol namespaces (a name-keyed
route cannot know which kind a file declares), and applies PHP's per-kind case rules via
`NameKind::normalize()`. A test pins the parse *count* at construction, which is
not a cost measurement; the set is explicit and usually tiny.

**"Which node declares this name" is answered by `Index\DeclarationScanner`**, which
reports every class-like, function and constant an AST declares — at any depth, paired
with its declaring node (`Declaration`). Every consumer but one (tracked below) reads
it: on-disk and open-document lookup, the write path's lookup half, the
`autoload.files` index, and completion's file-function query, so none can disagree
about what a file declares. Hand-written
traversals are how a `function_exists`-guarded polyfill came to resolve on hover while
being invisible to completion, and how its `class_exists` twin dropped out of
open-document lookup. Do NOT write a new one; a rule about what counts as a declaration
is a change to the scanner.

One traversal survives it, tracked: `Index\SymbolExtractor` rebuilds FQNs by hand rather
than reading `namespacedName`. That is not licence for a second.

The same derived index also answers the backend's `childrenOf`, merged with the
directory listing by `CompositeNamespaceCatalog`. Enumeration is not optional: §4.2
requires lookup and enumeration to draw on the same backends, so a name that resolved
on hover while being invisible to completion is the split this tier exists to prevent.

The write path is **`SymbolSink`** (`DocumentSymbolSink`), which registers a document's
symbols and indexes them. Registration is kind-parameterized like lookup: the sink hands
`OpenDocumentBackend` `DeclaredSymbol`s built by `DeclarationSymbolInfoFactory`, the same
factory the on-disk read path uses, so a new kind is a case there rather than another
parameter on the backend. A declaration at any depth is registered, not just a top-level
one — a class or function guarded by `class_exists`/`function_exists` is a name the file
validly declares, and the on-disk backends resolve one, so opening the file must not make
it disappear.
**`KnowledgeStack::forProject`** assembles the read composite and the write sink,
sharing one open-document backend and symbol index.

**External-file-change invalidation** (RFC 1 §5.2, §5.3) is a third write-path
producer alongside the editor lifecycle. `SymbolSink extends Cache\Invalidatable`, so
`invalidate($uri)` drops the on-disk cache for a file changed outside the editor and
the next query re-reads disk. It fans out to the cached on-disk backends (also
`Invalidatable`): `FilesystemBackend` evicts that file's class-likes and functions (a
path→key reverse map), `CachedNamespaceCatalog` drops its listings, and the locator composite
re-derives the `autoload.files` index if the changed file is in that set — evicting
only the `ClassInfo` cache would leave the name→file map itself stale.
Two triggers reach it:
the `workspace/didChangeWatchedFiles` notification (`DidChangeWatchedFilesHandler`)
and `didClose` (so a closed-after-edit file re-reads disk). Watched files are
registered dynamically after `initialized` (`WatchedFilesRegistrar` via the outbound
`ClientConnection` — no static server capability exists), gated on the client's
`dynamicRegistration`; an unregistered client follows the §7 fallback (no invalidation
until a file is opened and closed).

Prefix search (`search(prefix, NameKind)`) is kind-parameterized: the open-document
backend answers for every kind; the on-disk and built-in backends return empty
(project-wide on-disk search is the deferred workspace-index scope, RFC 1 §3).
Function search, and the migration of the consumers still calling
`FunctionRepository`, are later Step 3b slices; constant reach is S3.8b.

- **MemberResolver** — Finds methods/properties/constants on a class, traversing the inheritance chain via `supertypes()`; reads class metadata through `SymbolSource`. Returns domain objects (`MethodInfo`, `PropertyInfo`).
- **ClassInfoFactory** (`DefaultClassInfoFactory`) — Creates `ClassInfo` from AST nodes or reflection.

### Domain Objects

Typed representations of code constructs in `src/Domain/`:

- `ClassInfo` — Class/interface/trait/enum metadata (methods, properties, constants, inheritance)
- `MethodInfo`, `PropertyInfo`, `ConstantInfo`, `EnumCaseInfo` — Member metadata
- `ParameterInfo`, `FunctionInfo` — Function/method parameter details
- `Visibility` enum — Public/protected/private with comparison logic
- `ClassName`, `MethodName`, `PropertyName` — Typed identifiers
- `TypeFactory` — Creates Type domain objects from AST nodes and reflection
- `NamespacePath` — Segment operations on namespace and fully-qualified-name strings; the one place a name is split into namespace and short name, and the one place a namespace path is case-folded

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
means stop. Do not collapse these back into `?Message`. A frame that is recognisably
a *Response* (an id with a `result`/`error` and no method) is the client's reply to a
server-initiated request; the server does not correlate those, so `read()` drops it
like a Notification rather than answering it.

`TransportInterface::write()` takes any `OutgoingMessage` — a `ResponseMessage` or a
server-initiated `OutgoingRequest` — so responses and server→client requests share one
framed channel. Server-initiated requests go through **`ClientConnection`**
(`TransportClientConnection`); today the sole use is dynamic capability registration
(`client/registerCapability`). Broader server-initiated output (diagnostics, cancellation)
is the deferred scheduler tier (Plan 0002 Step 6).

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
`initialize` has produced a result. On `initialized`, it runs its `InitializedListener`s
against the settled `SessionCapabilities` — the point where dynamic capability
registration proceeds (e.g. `WatchedFilesRegistrar`) — rather than growing a dependency
on each feature that needs to act post-initialize.

`Server` takes the `LifecycleHandler` separately from the other handlers and
prepends it to the dispatch list itself, so the instance the gate consults cannot
diverge from the one that handles `initialize`/`shutdown`.

### Guidelines for New Code

- **Keep code DRY.** Be on the lookout for existing tools that will solve your problem; NEVER copy-and-paste. Extract repeated logic aggressively.
- **Use repositories, not direct reflection.** `MemberResolver::findMethod()` handles inheritance; raw `ReflectionClass` does not integrate with open documents.
- **Use domain objects.** Return `MethodInfo`/`PropertyInfo` from lookups, not raw AST nodes or reflection objects.
- **Add factory methods to domain objects** for new construction patterns (e.g., `FunctionInfo::fromNode()`, `FunctionInfo::fromReflection()`).
- **Check existing utilities before writing AST traversal.** Search `ScopeFinder` and handlers for similar patterns before creating new `NodeVisitorAbstract` implementations. Duplicate traversal logic should be extracted to utilities.
- **Use `ExpressionResolver` for expression types.** `resolve(Expr, $ast)` returns a `ResolvedSymbol` whose `getType()` is the expression's type, `$this` included. Inside handlers, prefer `CodeResolver` (see Architecture Invariants) over calling this directly.
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
- Call `MemberResolver`, `SymbolSource`, or `ExpressionResolver` directly

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

**One route per fact.**

Where a fact has, or could have, more than one complementary implementation — a parsed
tree and a text-derived skeleton, an open document and a file on disk, a cache and what
it caches — the code has exactly one interface for it, and one shape around it:

- Every implementation implements the interface, including the composite and any
  decorator. The composite, always named `Composite<Interface>`, holds an ordered
  `iterable` of the interface and answers by asking its members in order: a lookup returns the
  first non-null answer, an enumeration merges every answer with the earlier member
  winning a name clash, and it holds no other logic. A decorator such as a cache
  implements the interface and wraps one.
- Syntax has one node model, php-parser's. `SyntaxSource` returns php-parser nodes, and
  an implementation built on another parser converts its tree into that model.
- A consumer is typed on the interface, holds one of it, and never names an
  implementation. Only the composition roots (`Server::forProject` and
  `KnowledgeStack::forProject`, the hand-written container) name one; a test that
  needs production wiring gets it from a factory under `tests/`, never from `src/`.
- The interface, its composite, and every implementation share one namespace named for
  the interface under the tier that owns it.

`NamespaceCatalog` with `CompositeNamespaceCatalog` and `CachedNamespaceCatalog` is the
shape today. A consumer that names an implementation, calls a static method on one, or
holds two routes to one fact has moved the problem, not removed it: that is how the
parse-health M×N happened, with each positional question checking the tree and then
calling the regex, and not all of them doing so. A null or empty check on one route
before calling another is the pattern to refuse. `tests/Architecture/OneRoutePerFactTest.php`
derives every implementation from its interface, checks the composite's name and the
family's namespace, and fails when anything but the root names an implementation. A
route with no interface yet is a transitional row naming its concrete classes and
holders. A condition that fails today is recorded on its row with the manifest step that
clears it; the row asserts it still fails, then skips. Adding a pending entry is a Loosen
edit; clearing one is the step's work. A new fact with more than one route is a new row.

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
- **Some fixtures back the Step P parity goldens** (`tests/Parity/`). Changing one —
  *even additively* — changes its golden. Recapture with `UPDATE_GOLDENS=1` and review
  the diff; see `tests/Parity/README.md`. The parity corpus is deliberately small, so
  prefer other fixtures when adding cases for unrelated tests.

## LSP Protocol

Server communicates over stdio. Test with any LSP client; `docs/vim-ale.md` has Vim setup notes.

## Project Guidelines

- Aggressively, proactively refactor. Consistent behavior is paramount to long-term success.
- ALWAYS follow TDD.
- Test coverage MUST be 100% for all new code. NO EXCEPTIONS. If a branch should be unreachable, it should either be rewritten to be eliminated or, if impractical, throw a logic exception and marked for coverage ignore. Prefer to eliminate the dead branch.
- Update documentation and guidelines when making changes. It is critical to keep this up to date to avoid drift and redundant work.
