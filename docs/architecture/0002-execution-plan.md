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

**Merge dependency.** This plan references RFC 1 (`0001-foundational-architecture.md`)
throughout. That file must land first, or in the same merge; until it does, the
`§x.y` references and `Companion-To` dangle. Land 0001 before or with 0002.

## 1. Principles

- **Strangler-fig, always green.** Introduce a seam, migrate consumers onto it in
  small commits, then reshape behind it. No big-bang rewrite; every step merges on
  its own and keeps the suite passing.
- **Enforcement lands with the seam — scoped, not baselined.** When a step
  introduces an invariant's seam, its §8.1 enforcement mechanism lands in the same
  step. During a partial migration a rule ships with an **explicit, code-level
  scope** (an allowlist of the classes not yet migrated, narrowed as later steps
  complete) — never a baseline entry, which would violate the standing
  no-baseline-growth rule. An invariant with no mechanism is a tracked gap, not a
  step that "passed."
- **Parity is a harness to be built, not assumed.** The repo's only parity test
  today is `TypeGraphParityTest`, scoped to member resolution. The behavior-
  preserving claims in Steps 2–4 cover enumeration, search, and the write path,
  which it does not exercise. Building the corpus-wide harness is real, scoped work
  (Step P) that gates Steps 2–4. Steps that *intend* to change behavior (Step 3's
  function and constant reach) carry new fixtures instead of parity.
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
| `ComposerAutoloadMap` (dedupe the double instance) | Replaceable cache abstraction (PSR-6/16 seam) | `TextFallbackHelper` (narrowed to FQN recovery) |
| Completion coordinator + `*Candidates`; transport (amphp) | Enforcement rules (§8.1); `SymbolIdentity` (Step 3+) | `ClassLocator` → kind-general `SymbolLocator` |
| Fixture tooling + `TypeGraphParityTest` | Corpus parity harness (Step P); scheduler tier (Step 6) | |

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

Reframed from "build an AST cache" to **spike-first**. Static inspection found
eight `parser->parse()` call sites reachable from a single completion request
(`SymbolResolver` alone), so a request appears to reparse the same content several
times; the spike measures the *actual* cost before anything is built.

Spike: instrument `ParserService` with a parse counter + timer; time
parse-plus-both-visitor-passes on small / medium (~700-line) / pathological
(multi-thousand-line) files; measure AST RAM vs source size; multiply parse time by
the observed per-request reparse count.

Decision rule: if `parse_ms × reparse_count` is imperceptible on realistic files,
ship only **request-scoped dedup** (memoize for the duration of one request, then
discard) and skip the standing cache. Add a standing, version-keyed cache only if
large files cross a perceptibility threshold, and even then scope it to **open
documents only** (bounded by open-file count), never disk; a standing cache uses
the replaceable cache abstraction introduced in Step 3, not hard-coded memoization.

*Acceptance:* the spike's numbers and the cache decision are recorded. If dedup
only: a request triggers **one parse per request** (counter test). If a standing
cache is added: **one parse per document version**, open documents only, behind the
Step 3 cache abstraction. No standing cache is added without measurement backing it.

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

### Step P — Resolution & enumeration parity harness (gates Steps 2–4)

New infrastructure that the strangler steps depend on. `TypeGraphParityTest` covers
member resolution only; Steps 2–4 migrate namespace enumeration, class-like prefix
search, class-like lookup, and the document write path — none of which it exercises.

*Goal:* a corpus-wide golden harness that records, over the fixture corpus, the
observable outputs of the surfaces Steps 2–4 touch — class-like lookup results,
`childrenOf` enumeration, prefix-search results, and the symbol state produced by a
document open/update/close — so any behavior-preserving step can be proven identical
before and after.

*Acceptance:* the harness captures those outputs across the corpus and fails on any
diff; it runs in CI; a deliberate change to a migrated surface makes it red (proving
it has teeth). This step can run in parallel with Steps 0 and 1.

### Step 2 — `SymbolSource` / `SymbolSink` facade (strangler, no behavior change)

*Goal:* introduce the read/write knowledge seam over today's collaborators, with no
behavior change. Detailed in Section 5.

*Acceptance:* read + write interfaces exist; a facade implements them by delegating
to `ClassRepository` / `SymbolIndex` / `NamespaceCatalog` and the existing write
paths; class-like lookup, class-like prefix search, namespace enumeration, and the
document write path flow through the interfaces; **no *migrated* consumer names
`ClassRepository`, `SymbolIndex`, or `NamespaceCatalog` directly** (the function/
constant path still names `FunctionRepository` — deferred to Step 3, §5.5); the
Step P harness is identical before/after; the §4.2 "no direct reflection/index/
autoload/repository outside a backend" rule ships **scoped to exempt
`FunctionRepository` and the un-migrated function path** (the exemption is an
explicit allowlist in the rule, removed in Step 3 — not a baseline entry).

### Step 3 — Backend unification + function/constant reach

The core payoff, and the one behavior-changing bundle. Split into sub-steps so the
risk is not one commit, and so its `SymbolResolver` edits can be serialized against
Step 4 (Section 6).

- **3a — Backends + one write path (behavior-preserving).** Refactor the tiers into
  named backends (OpenDocument, Workspace, Vendor, Builtin) behind a composite with
  fixed precedence (§5.3); collapse the double write into one parse + one store on
  `didChange`; dedupe the double `ComposerAutoloadMap`; introduce the **replaceable
  cache abstraction (PSR-6/16 seam, §5.3)** that backend caches use. Proven by the
  Step P harness. `getFileFunctions` is subsumed here: the open-document backend
  knows a document's functions, so the query disappears rather than moving.
- **3b — `SymbolLocator` + function/constant reach (behavior-changing).** Generalize
  `ClassLocator` to a kind-agnostic `SymbolLocator`; fold in `autoload.files`; give
  `lookupFunction` / `lookupConstant` real project reach; migrate `FunctionCandidates`
  to `search`; remove the Step 2 rule exemption. Proven by **new fixtures**, not
  parity.
- Close-of-edited-file and external-change invalidation behave per §5.3.

*Known temporary divergence:* the Builtin backend stood up in 3a is not yet
environment-parameterized; §4.7 conformance arrives in Step 5. Tracked, not clean.

### Step 4 — `SymbolResolver` decomposition

*Goal:* split the god class into positional layer + thin glue + `TypeClassifier`,
and finish the interface split.

*Acceptance:* the positional layer (node-at-offset, scope, member/call detection,
text fallback, **plus the name-resolution context queries `getImports` /
`getNameContext`** — these are document-scoped name context, not FQN-knowledge, so
they live here) is its own unit; `TypeClassifier` owns the predicates; `SymbolResolver`
is thin glue over the positional layer and `SymbolSource`; **`CodeResolver` is reduced
to the positional-facing interface — its knowledge-facing responsibilities are served
by `SymbolSource` (there is no second knowledge interface)**; the no-`instanceof`-on-
concrete-`Type` and no-branch-on-resolved-kind rules pass, as does the §4.6
"no `new` of a `Type` implementation outside the factory" rule; parity green.

### Step 5 — Environment-parameterized built-ins

*Goal:* make built-in knowledge depend on the project's target, not the server's
runtime (§4.7), closing the Step 3 divergence.

*Acceptance:* `TargetEnvironment` derived from `composer.json` and updated on
`workspace/didChangeConfiguration`; the Builtin backend resolves against it, keyed
by it through the Step 3 cache abstraction; a change of target invalidates / re-keys
the cache; a version-gated symbol is offered or withheld per target (fixture).

### Step 6 — Scheduler / async tier (deferred until a push feature needs it)

*Goal:* server-initiated output and cancellation, confined to the transport /
scheduler tier (§6).

*Acceptance:* `$/cancelRequest` abandons superseded work; debounced
`publishDiagnostics` pushes on change; background work does not starve interactive
requests and is cancelable; optional components (`pcntl`, `ext-parallel`) are
feature-detected with a synchronous fallback (Fibers / FFI may be relied on).

### Unscheduled §8.1 mechanisms

- §4.6 "no `new` of a `Type` impl outside the factory" — lands in Step 4 (above).
- §4.10 client conformance defects — review-only by design; carries no seam. The
  running defect list lives in RFC 1 Appendix B (currently: ale `textEdit` range,
  ale#4274). No step owns it; it is maintained on review.

## 5. Step 2 in depth

### 5.1. The design decision that shapes the interface

Knowledge queries are **FQN-based** (§4.4). `SymbolSource` takes already-qualified
names; turning "`foo()` in this namespace with these imports" into candidate FQNs
is positional / name-context work and stays with the caller (today's `NameContext`
/ `ScopeFinder`, which land in the positional layer in Step 4). That is what lets
the interface drop the `document` / `$ast` parameters.

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

    public function locate(QualifiedName $name, NameKind $kind): ?SymbolDefinition; // kind-neutral name → def site
    public function childrenOf(NamespaceName $namespace): NamespaceContents;         // enumeration
    /** @return list<SymbolDefinition> */
    public function search(string $prefix, NameKind $kind): array;                  // prefix is a fragment, see 5.3
}

interface SymbolSink
{
    public function openDocument(TextDocument $document): void;
    public function updateDocument(TextDocument $document): void;
    public function closeDocument(string $uri): void;
}
```

### 5.3. The name-type model

To stop the two typing models fighting before they are built:

- `QualifiedName` is the base FQN value type — a namespace path plus a short name,
  **kind-neutral**.
- `ClassLikeName`, `FunctionName`, `ConstantName`, `NamespaceName` extend / wrap it
  and **carry their kind intrinsically** (each exposes `kind(): NameKind`). These are
  the primary currency; the per-kind `lookup*` methods take the matching one, so the
  kind is implicit and `NameKind` is not passed.
- `locate(QualifiedName, NameKind)` is the kind-agnostic entry, used when the caller
  has an FQN whose kind is known only from syntactic position and has not minted a
  typed subtype. `NameKind` is **not** redundant here precisely because the input is
  the kind-neutral base type.
- `search`'s `prefix` is a **partial fragment** the user is typing ("`Log`"), not a
  complete identifier, so a bare `string` is correct — §5.1's typed-identifier rule
  is about identifiers, not search fragments. `kind` selects which namespace to
  search.

### 5.4. `SymbolDefinition` is lightweight; detail comes from a lookup

`locate` / `search` return a `SymbolDefinition` = **identity + kind + location**,
not full metadata. Initially `SymbolIdentity` is just the `(FQN, kind)` pair, which
the existing index `Symbol` already encodes (name, fqn, kind, location) — so
`SymbolDefinition` can reuse `Symbol` in Step 2, and open-decision "reuse `Symbol`"
and the §5.6 "carry a stable identity" hook are the same thing, not a conflict.

Completion that needs richer detail (a function's signature/parameters) must **not**
regress when `FunctionCandidates` moves to `search` in Step 3. Two acceptable ways,
decided then: a follow-up `lookupFunction` per surviving candidate (the list is
prefix-filtered and capped at 100, so bounded), or a lazy `completionItem/resolve`
capability (ties to Step 1 negotiation) that fills detail on demand — the LSP-idiomatic
answer. Either keeps `search` results lightweight while preserving today's rendering.

### 5.5. Facade and wiring

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
        // Step 3a: collapse to a single parse + single store.
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

### 5.6. Design answers baked into the shape

- **`lookupClassLike`, not `lookupClass`.** It returns `ClassInfo`, which already
  carries a `ClassKind` discriminator. A future struct is a new `ClassKind` case
  reached through the same method, predicates, and `supertypes()` traversal (§4.5) —
  not a new method.
- **The method set is closed.** PHP has exactly three symbol namespaces, so the
  lookup set is those three and cannot grow with new kinds. That is the structural
  answer to "do per-kind methods recreate M×N": they mirror the language's own
  symbol table, which does not expand.
- **No `lookupNamespace`.** A namespace has no declaration site; it exists iff
  something is declared under it. "What is in `Psr\Log`" is `childrenOf`, and
  existence is that being non-empty (add a thin `namespaceExists` only if hot).
- **Backends stay kind-agnostic — the M×N guardrail.** Two cross-products must stay
  closed: *consumers × kinds* (one kind-dispatch point in the glue, per §4.5's
  syntactic-position routing) and *backends × kinds* (backends resolve uniformly —
  one `resolve(QualifiedName, NameKind)` — with the typed `ClassInfo` / `FunctionInfo`
  constructed at the facade via the existing factories, §4.6). A new kind is then a
  factory + a facade accessor, never a change to every backend.

### 5.7. Why functions and constants are deferred to Step 3

Moving them in Step 2 would not be behavior-preserving. Function lookup today is
`FunctionRepository::get(string, array $ast)` — document-scoped and reflection-only
otherwise. A clean `lookupFunction(FunctionName)` either keeps the AST dependency
(violates §4.4) or backs it with open-doc data (changes coverage, fails the Step P
harness). Their coverage is *supposed* to expand — but that is Step 3b's job, gated
by its own new fixtures.

### 5.8. Groundwork this lays for workspace features (#264)

Deferred, but the facade is the template and the hooks are cheap to leave in place:

- **`search()` is `workspace/symbol`** once Step 3 gives it project-wide reach — the
  first workspace feature, no new interface.
- **The single write path is the reference-extraction choke point.** Adding usage-
  site extraction later is one additive change at one place.
- **`SymbolDefinition` carries a stable `SymbolIdentity`** (the `(FQN, kind)` pair
  today) so a future reverse index keys on the same identity the forward side emits.
- **Workspace queries are a sibling interface** (`WorkspaceQuery`), backed by the
  same backend composite plus a reverse index the same `SymbolSink` populates — the
  Step-2 facade pattern reused, not `SymbolSource` grown (§4.2, Appendix B).

## 6. Sequencing

```
Step 0 ─┐
Step 1 ─┼─ (independent of each other and of P/2)
Step P ─┘   parity harness — gates 2–4

Step 2 ──► Step 3a ──► Step 3b ──► Step 5
                  └────► Step 4    (see caveat)
Step 6: last / independent (needs a consumer feature such as #266)
```

- **Step P gates Steps 2–4** — the behavior-preserving claims are unprovable without
  it, so it lands first (it can be built in parallel with Steps 0/1).
- **Steps 3 and 4 both edit `SymbolResolver`** — Step 3b rewrites its function/
  constant lookups; Step 4 extracts its positional layer. These are not freely
  parallel. Serialize the `SymbolResolver`-editing slices: do Step 3b's lookup
  migration first, then Step 4's extraction (or vice versa), but not concurrently.
  Step 4's `TypeClassifier` and interface-split slices are independent of Step 3 and
  may proceed alongside.

Recommended first PR: Step 0's spike, with Step P and Step 1 in parallel; Step 2
once Step P is green.

## 7. Open decisions (resolve at implementation time)

- The perceptibility threshold and cache decision from the Step 0 spike.
- Whether `FunctionName` / `ConstantName` / `NamespaceName` land in Step 2 as prep
  or in Step 3 with their lookups (lean: Step 3, to avoid an unused-type commit;
  `NamespaceName` is needed by `childrenOf` in Step 2, so it lands then).
- Whether completion detail after the `search` migration comes from a follow-up
  `lookupFunction` or a `completionItem/resolve` capability (§5.4).
- Whether the Workspace backend is lazy-only or gains bounded background indexing
  (only relevant if the workspace scope is taken up).
