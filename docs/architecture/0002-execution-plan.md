# PHP-LSP Execution Plan (companion to RFC 1)

    Document:      Execution Plan 0002
    Status:        Draft (non-normative)
    Companion-To:  0001-foundational-architecture.md
    Date:          2026-07-20

## 0. Nature of this document

This is a **birds-eye plan**, not a specification and not issue-ready work items.
It sequences the move from the code as it stands to the end state RFC 1 defines,
and it is deliberately non-normative: RFC 1 owns the requirements (its MUST/SHOULD
keywords bind); this document owns the *order*, the *reuse*, and the *acceptance
criteria* for getting there. Where the two disagree, RFC 1 wins and this document
is corrected.

Section references of the form "§4.2" are to RFC 1.

## 1. Principles

- **Strangler-fig, always green.** Introduce a seam, migrate consumers onto it in
  small commits, then reshape behind it. No big-bang rewrite; every step merges on
  its own and keeps the suite passing.
- **Enforcement lands with the seam.** The moment a step introduces an invariant's
  seam, its §8.1 enforcement mechanism (a PHPStan rule, an architecture test, or a
  parity test) lands in the same step. An invariant with no mechanism is a tracked
  gap, not a step that "passed."
- **Parity tests are the safety net.** Behavior-preserving steps are proven so: the
  set of symbols/members the system resolves over the fixture corpus is identical
  before and after. Steps that *intend* to change behavior (Step 3's function and
  constant reach) carry new fixtures instead of parity.
- **Lazy-first.** The foundation resolves on demand; it does not walk the project
  up front (Section 3).
- **Measure before caching.** Standing caches are justified by numbers, not
  assumed (Section 4 / Step 0).

## 2. What is reused, new, and rewritten

| Reused (rewrapped, not rewritten) | New | Substantially rewritten |
|---|---|---|
| `Type` + `TypeFactory`; `MemberResolver::supertypes()` | `SymbolSource` / `SymbolSink` + backend composition | `DefaultFunctionRepository` (AST-in signature dies) |
| `NamespaceCatalog` + 3 sources + `Cached*` | `SessionCapabilities` + negotiation + encoding edge | Open-doc double store (`SymbolIndex` + `documentClasses`) |
| `DefaultClassRepository` tiering (becomes backend logic) | `TargetEnvironment` + env-keyed built-in cache | `SymbolResolver` (decomposed) |
| `ComposerAutoloadMap` (dedupe the double instance) | Enforcement rules (§8.1) | `TextFallbackHelper` (narrowed to FQN recovery) |
| Completion coordinator + `*Candidates`; transport (amphp) | `SymbolIdentity` (Step 3+) | `ClassLocator` → kind-general `SymbolLocator` |
| Fixture tooling + `TypeGraphParityTest` | Scheduler/async tier (Step 6) | |

## 3. Indexing posture (lazy-first)

The foundation does **not** require indexing the project up front. Resolution is
lazy and on demand, which is how classes and namespaces already work. An index is
needed only for the deferred workspace scope.

| Capability | Needs an index? | Mechanism |
|---|---|---|
| `lookupClassLike(FQN)` | No | PSR-4 `findFile` → parse that one file (today's `ClassRepository`) |
| `childrenOf(namespace)` | No | `scandir` that one directory (today's `NamespaceCatalog`) |
| Function / constant reach (Step 3) | Bounded, small | parse the `autoload.files` set (explicit, tiny; #181) + open docs |
| Project-wide `search(prefix)` / `workspace/symbol` | Yes | deferred workspace scope |
| Reverse queries (find-references, implementations) | Yes (reverse index) | deferred workspace scope |

Notes:

- Functions and constants have **no name→file map** (unlike PSR-4 classes);
  `autoload.files` is an explicit, usually tiny list. So their project reach is a
  small, bounded index of that set — not a project walk.
- Background/eager indexing is optional and bounded (§5.3). `WorkspaceIndexer` is
  revived only if/when the workspace scope is taken up; otherwise it is deleted.
- On-disk backends cache **derived info** (`ClassInfo`, symbols — small), never raw
  ASTs. `ClassRepository` already does this; preserve it.

## 4. The steps

### Step 0 — Parse cost: measure, then dedup

Reframed from "build an AST cache" to **spike-first**. The measured waste (5–7
parses) is *within a single request*, so the default fix is request-scoped
memoization (transient, ~one AST live), not a standing cache.

Spike: instrument `ParserService` with a parse counter + timer; time
parse-plus-both-visitor-passes on small / medium (~700-line) / pathological
(multi-thousand-line) files; measure AST RAM vs source size; multiply parse time by
observed reparse count.

Decision rule: if `parse_ms × reparse_count` is imperceptible on realistic files,
ship only **request-scoped dedup** and skip the standing cache. Add a standing,
version-keyed cache only if large files cross a perceptibility threshold, and even
then scope it to **open documents only** (bounded by open-file count), never disk.

*Acceptance:* a request triggers one parse per document version (counter test);
the spike's numbers and the cache/no-cache decision are recorded. No standing cache
is added without measurement backing it.

### Step 1 — Capability negotiation + encoding edge (orthogonal)

*Goal:* stand up the protocol-negotiation tier and fix position encoding at the
boundary (§4.8, §4.9, §4.10, §9).

*Acceptance:* `initialize` reads `ClientCapabilities` into an immutable
`SessionCapabilities`; advertised `ServerCapabilities` derive from the implemented
set; `positionEncoding` negotiated with UTF-16 default; a multibyte fixture
round-trips positions correctly through one internal representation; hover markup /
snippet shaped via `SessionCapabilities`; malformed frame → error response, not a
crash; pre-`initialize` / post-`shutdown` lifecycle state enforced; a rule confines
raw `initialize` params to the negotiation component.

### Step 2 — `SymbolSource` / `SymbolSink` facade (strangler, no behavior change)

*Goal:* introduce the read/write knowledge seam over today's collaborators, with no
behavior change. Detailed in Section 5.

*Acceptance:* read + write interfaces exist; a facade implements them by delegating
to `ClassRepository` / `SymbolIndex` / `NamespaceCatalog` and the existing write
paths; class-like lookup, class-like prefix search, namespace enumeration, and the
document write path all flow through the interfaces; **no consumer names those
concrete collaborators anymore**; the parity test is identical before/after; the
"no direct reflection/index/autoload outside a backend" rule passes for migrated
consumers. Function/constant resolution and `FunctionCandidates` are explicitly
deferred to Step 3.

### Step 3 — Backend unification + one write path + function/constant reach

*Goal:* collapse the tiers into named backends; give functions and constants
project reach. This is the core payoff and the one step that *intends* to change
behavior.

*Acceptance:* named backends (OpenDocument, Workspace, Vendor, Builtin) behind a
composite with fixed precedence (§5.3); one parse + one store on `didChange` (the
`SymbolIndex` / `documentClasses` double store is gone); a single
`ComposerAutoloadMap`; `ClassLocator` generalized to a kind-agnostic `SymbolLocator`
with `autoload.files` folded in; **a function and a global constant declared in
another project file resolve for hover / definition / completion** (new fixtures);
close-of-edited-file and external-change invalidation behave per §5.3;
`FunctionCandidates` migrated to `search`.

### Step 4 — `SymbolResolver` decomposition

*Goal:* split the god class into positional layer + thin glue + `TypeClassifier`.

*Acceptance:* the positional layer (node-at-offset, scope, member/call detection,
text fallback) is its own unit; `TypeClassifier` owns the predicates; `SymbolResolver`
is thin glue over the positional layer and `SymbolSource`; `CodeResolver` is split
into positional-facing and knowledge-facing interfaces; the no-`instanceof`-on-
concrete-`Type` and no-branch-on-resolved-kind rules pass; parity green.

### Step 5 — Environment-parameterized built-ins

*Goal:* make built-in knowledge depend on the project's target, not the server's
runtime (§4.7).

*Acceptance:* `TargetEnvironment` derived from `composer.json` and updated on
`workspace/didChangeConfiguration`; the built-in backend resolves against it; a
change of target invalidates / re-keys the built-in cache; a version-gated symbol
is offered or withheld per target (fixture).

### Step 6 — Scheduler / async tier (deferred until a push feature needs it)

*Goal:* server-initiated output and cancellation, confined to the transport /
scheduler tier (§6).

*Acceptance:* `$/cancelRequest` abandons superseded work; debounced
`publishDiagnostics` pushes on change; background work does not starve interactive
requests and is cancelable; optional components (`pcntl`, `ext-parallel`) are
feature-detected with a synchronous fallback (Fibers / FFI may be relied on).

## 5. Step 2 in depth

### 5.1. The design decision that shapes the interface

Knowledge queries are **FQN-based** (§4.4). `SymbolSource` takes already-qualified
names; turning "`foo()` in this namespace with these imports" into candidate FQNs
is positional / name-context work and stays with the caller (today's `NameContext`
/ `ScopeFinder`). That is what lets the interface drop the `document` / `$ast`
parameters.

### 5.2. Interfaces (illustrative, not normative)

```php
interface SymbolSource
{
    // PHP has exactly three symbol namespaces: class-likes, functions, constants.
    // These three are therefore a CLOSED set — new "kinds" (structs, payload
    // enums) live inside the class-like namespace and add a ClassKind case, not a
    // method.
    public function lookupClassLike(ClassLikeName $name): ?ClassInfo;   // class/interface/trait/enum/future struct
    public function lookupFunction(FunctionName $name): ?FunctionInfo;   // Step 3
    public function lookupConstant(ConstantName $name): ?ConstantInfo;   // Step 3

    public function locate(QualifiedName $name, NameKind $kind): ?SymbolDefinition; // carries SymbolIdentity
    public function childrenOf(string $namespace): NamespaceContents;               // enumeration
    /** @return list<SymbolDefinition> */
    public function search(string $prefix, NameKind $kind): array;                  // prefix search
}

interface SymbolSink
{
    public function openDocument(TextDocument $document): void;
    public function updateDocument(TextDocument $document): void;
    public function closeDocument(string $uri): void;
}
```

### 5.3. Design answers baked into the shape

- **`lookupClassLike`, not `lookupClass`.** It returns `ClassInfo`, which already
  carries a `ClassKind` discriminator (class/interface/trait/enum). A future struct
  is a new `ClassKind` case reached through the same method, same predicates, and
  same `supertypes()` traversal (§4.5) — not a new method.
- **The method set is closed.** PHP has exactly three symbol namespaces, so the
  lookup set is those three and cannot grow with new kinds. This is the structural
  answer to "do per-kind methods recreate M×N": they mirror the language's own
  symbol table, and it does not expand.
- **No `lookupNamespace`.** A namespace has no declaration site; it exists iff
  something is declared under it. "What is in `Psr\Log`" is `childrenOf` (an
  enumeration), and existence is `childrenOf(...)` being non-empty (add a thin
  `namespaceExists` only if it proves hot). Go-to-definition on a namespace is a
  glue policy that picks a representative location from enumeration.
- **Backends stay kind-agnostic — the M×N guardrail.** Two cross-products must stay
  closed: *consumers × kinds* (controlled by a single kind-dispatch point in the
  glue, per §4.5's syntactic-position routing) and *backends × kinds* (controlled by
  making backends resolve uniformly — one `resolve(QualifiedName, NameKind)` — with
  the typed `ClassInfo` / `FunctionInfo` constructed at the facade via the existing
  factories, §4.6). A new kind then means a factory + a facade accessor, never a
  change to every backend.

### 5.4. Facade and wiring

```php
// Illustrative Step-2 implementation: pure delegation, no logic.
final class DelegatingSymbolSource implements SymbolSource, SymbolSink
{
    public function __construct(
        private ClassRepository $classes,        // lookupClassLike → get()
        private SymbolIndex $index,              // search → findByPrefix(); locate → findByFqn()
        private NamespaceCatalog $catalog,       // childrenOf → childrenOf()
        private DocumentIndexer $indexer,        // write path A (existing)
        private ClassInfoFactory $classFactory,  // write path B: registerDocumentClasses (existing)
        private ParserService $parser,
    ) {}

    public function updateDocument(TextDocument $doc): void
    {
        // Step 2: reproduce today's DOUBLE write behind ONE method.
        // Step 3: collapse to a single parse + single store.
        $this->registerClasses($doc);   // → classes->updateDocument(...)
        $this->indexer->index($doc);     // → index
    }
}
```

Consumer migration (construction moves to `Server.php`):

| Consumer | Today | Step 2 | Behavior |
|---|---|---|---|
| `ClassCandidates` | `SymbolIndex` | `SymbolSource::search` | identical (same backing) |
| `NamespaceCandidates` | `NamespaceCatalog` | `SymbolSource::childrenOf` | identical |
| `SymbolResolver` (class lookups) | `ClassRepository` | `SymbolSource::lookupClassLike` | identical |
| `TextDocumentSyncHandler` | `DocumentIndexer` + `ClassInfoFactory` + `ClassRepository` | `SymbolSink` | identical (double-write hidden, not removed) |
| `FunctionCandidates`, function/constant resolution | unchanged | **unchanged** | deferred to Step 3 |

### 5.5. Why functions and constants are deferred to Step 3

Moving them in Step 2 would not be behavior-preserving. Function lookup today is
`FunctionRepository::get(string, array $ast)` — document-scoped and reflection-only
otherwise. A clean `lookupFunction(FunctionName)` either keeps the AST dependency
(violates §4.4) or backs it with open-doc data (changes coverage, fails parity).
Their coverage is *supposed* to expand — but that is Step 3's job, gated by its own
new fixtures. Step 2 migrates only what moves without changing behavior: class-likes
(already FQN-based), enumeration, class-like prefix search, and the write path.

### 5.6. Groundwork this lays for workspace features (#264)

Deferred, but the facade is the template and the hooks are cheap to leave in place:

- **`search()` is `workspace/symbol`** once Step 3 gives it project-wide reach — the
  first workspace feature, no new interface.
- **The single write path is the reference-extraction choke point.** Adding usage-
  site extraction later is one additive change at one place, not an M-place change.
- **`SymbolDefinition` should carry a stable `SymbolIdentity`** now, so a future
  reverse index keys on the same identity the forward side already emits (also what
  #268's anonymous classes need). Cheap now, expensive to retrofit.
- **Workspace queries are a sibling interface** (`WorkspaceQuery`), backed by the
  same backend composite plus a reverse index the same `SymbolSink` populates — the
  Step-2 facade pattern reused, not `SymbolSource` grown (§4.2, Appendix B).

## 6. Sequencing

```
Step 0 ─┐
Step 1 ─┼─ (independent of each other and of 2)
Step 2 ─┴─► Step 3 ─► Step 5
             └───────► Step 4   (may start once 2 lands; parts independent)
Step 6: last / independent (needs a consumer feature such as #266)
```

Step 2 is the only hard gate (blocks 3 and 4). Steps 0 and 1 run alongside it.
Recommended first PR: Step 0's spike, then Steps 1 and 2 in parallel.

## 7. Open decisions (resolve at implementation time)

- Whether `SymbolDefinition` is a new value object or the existing index `Symbol`
  renamed (lean: reuse `Symbol` first).
- Whether `FunctionName` / `ConstantName` typed identifiers land in Step 2 as prep
  or in Step 3 with their lookups (lean: Step 3, to avoid an unused-type commit).
- The perceptibility threshold and cache decision from the Step 0 spike.
- Whether the Workspace backend is lazy-only or gains bounded background indexing
  (only relevant if the workspace scope is taken up).
