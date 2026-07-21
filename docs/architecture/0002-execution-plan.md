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
- **Behavior-changing steps have a named revert path — but not the same one.**
  "Always green" is a merge-time property; it says nothing about production. A step
  that **swaps a source** behind a stable interface (Step 5's built-in source) lands
  behind a **config / feature flag**, so a regression can be switched off in place. A
  step that makes a **structural change** (Step 3b deletes `getFileFunctions`' last
  caller and removes a rule exemption) is not toggle-revertible — resurrecting deleted
  code is not a flag; its safety comes from **staged landing + the per-surface goldens
  (Step P) + being cleanly revertible by reverting its commits**. Each behavior-
  changing step states which profile it uses.

## 2. What is reused, new, and rewritten

| Reused (rewrapped, not rewritten) | New | Substantially rewritten |
|---|---|---|
| `Type` + `TypeFactory`; `MemberResolver::supertypes()` | `SymbolSource` / `SymbolSink` + backend composition | `DefaultFunctionRepository` (AST-in signature dies) |
| `NamespaceCatalog` + 3 sources + `Cached*` | `SessionCapabilities` + negotiation + encoding edge | Open-doc double store (`SymbolIndex` + `documentClasses`) |
| `DefaultClassRepository` tiering (becomes backend logic) | `TargetEnvironment` + version-aware built-in source (Step 5 — **deferred**) | `SymbolResolver` (decomposed) |
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
- Reach is scoped to declarations the model can *locate*: PSR-4 / classmap classes,
  the `autoload.files` set, and open documents. A function or constant defined as a
  **load side-effect** of a PSR-4 / classmap file (declared alongside a class in a
  file loaded for that class) is reachable at PHP runtime but invisible to this
  model. Scoping it out is deliberate — a known limitation, not complete reach.
- The **Builtin backend is reflection-backed today** (zero-index, instant lookup via
  `get_defined_functions()` / reflection), so it introduces **no** lazy-first
  exception. An exception would arise only if a future static, version-aware source
  is adopted (Step 5, **deferred**): such a source has no name→file map and would need
  a pre-derived index. That trade-off is deferred with the step.

## 4. The steps

### Step 0 — Parse cost: measure, then dedup

Reframed from "build an AST cache" to **spike-first**. Static inspection found
seven `parser->parse()` call sites in `SymbolResolver`, spread across completion,
hover, and definition paths, so a request may reparse the same content several
times; the spike measures the *actual* cost — and which paths actually hit it —
before anything is built.

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

Because a standing cache uses the Step 3 abstraction, Step 0 as the first PR delivers
**measurement plus, at most, request-scoped dedup** now; if the spike concludes a
standing cache is warranted, it lands later as a **Step 3 rider** once the abstraction
exists (Section 6). Only the request-scoped-dedup half of Step 0 is truly early.

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

Position handling needs its own regression net — Step P excludes the positional
surface, so one multibyte fixture is not enough. Step 1 carries a **position
round-trip corpus**: the existing cursor-marker fixtures re-run under the negotiated
encoding, plus dedicated multibyte cases. And Step 1 is not fully orthogonal to Step
4: Step 1's single internal representation has to be the one the Step 4 positional
layer consumes, or the encoding work is silently redone — coordinate the two.

### Step P — Resolution & enumeration parity harness (gates Steps 2–4)

New infrastructure that the strangler steps depend on. `TypeGraphParityTest` covers
member resolution only; Steps 2–4 migrate namespace enumeration, class-like prefix
search, class-like lookup, and the document write path — none of which it exercises.

*Goal:* a corpus-wide golden harness that records, over the fixture corpus, the
observable outputs of the surfaces Steps 2–4 touch — class-like lookup results,
`childrenOf` enumeration, prefix-search results, and the symbol state produced by a
document open/update/close — so any behavior-preserving step can be proven identical
before and after.

*Acceptance:* goldens are recorded **per surface** (class-like lookup, `childrenOf`
enumeration, prefix-search, write-path symbol state), so a step that intentionally
changes one surface (3b) rewrites only that surface's golden while the others stay
frozen and can still assert preservation. The harness fails on any diff and runs in
CI; a deliberate change to a migrated surface makes it red. Critically, **branch
coverage of the migrated production code** (the lookup / enumeration / search / write
surfaces) is measured *while the harness runs*: an unexecuted branch — say
`childrenOf`'s trait-via-parent edge — is a corpus gap to fill before the harness is
trusted. This is production-code branch coverage restricted to the surface classes
(the one tool that answers "does the corpus exercise this edge"), not the harness's
own coverage. A green run over a corpus that misses an edge is false confidence, and
the fixture corpus is small and curated, not real-world PHP. Goldens are
**spot-audited when first captured**, not merely diffed thereafter: branch coverage
proves a branch executed, not that the captured expectation is correct — a wrong
golden is green forever. May run in parallel with Steps 0 and 1.

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

- **3a — Backends + one write path (behavior-preserving).** A cluster of small PRs,
  not one — per the project's small-commit rule: (i) the replaceable cache
  abstraction (PSR-6/16 seam, §5.3), which also hosts any Step 0 standing cache;
  (ii) dedupe the double `ComposerAutoloadMap`; (iii) the named backends
  (OpenDocument, Workspace, Vendor, Builtin) behind a composite with fixed precedence
  (§5.3); (iv) collapse the double write so one parse feeds the write on `didChange`.
  Note (iv) is *one parse, one write path* — **not necessarily one data structure**:
  `SymbolIndex` (symbols) and the `ClassInfo` cache serve different consumers and may
  stay distinct, written transactionally from the same parse. Because the Step P
  harness compares only observable outputs, an internal divergence between the two
  structures could pass parity, so add a consistency check that both are written from
  the same parse and agree. Proven by the Step P harness.
- **3b — `SymbolLocator` + function/constant reach (behavior-changing).** Generalize
  `ClassLocator` to a kind-agnostic `SymbolLocator`; fold in `autoload.files`; give
  `lookupFunction` / `lookupConstant` real project reach (constant reach covers
  `const` declarations and literal-name `define()`; a **computed-name `define()`** is
  a runtime call invisible to static parse and is out of scope, per §3's locate-only
  limitation); migrate `FunctionCandidates`
  to `search` (which subsumes `getFileFunctions` — the open-document backend knows a
  document's functions, so that query disappears with its last caller); remove the
  Step 2 rule exemption. This step **both changes and preserves** behavior on the
  function surface: the added project reach is new (proven by **new fixtures**), but
  built-in and open-document function completion is *existing* behavior that must not
  regress. So the function-surface golden (Step P) is **captured before this step**
  from today's path (`get_defined_functions()['internal']` + open-doc functions) and
  frozen for the preservation half, and a **parity oracle** asserts the Builtin
  backend's function enumeration matches `get_defined_functions()` (as
  `TypeGraphParityTest` uses reflection for members). Revert profile: structural —
  revertible by reverting its commits, not a flag (§1).
- **External-file-change invalidation gets its own slice and acceptance**, not a
  hand-wave to §5.3 — it is a classic LSP correctness minefield.
  `workspace/didChangeWatchedFiles` (capability-gated, dynamic registration) and
  close-after-edit both invalidate cached / indexed workspace state. *Its own
  acceptance:* an external edit to an unopened file, a branch checkout, and a file
  deletion are each reflected on the next query; closing an edited file re-reads from
  disk rather than restoring the pre-edit cache (§5.3). When the client does not
  support watched-file notifications, the fallback (lazy re-read vs. no invalidation)
  is an open decision (§7).

*Known tracked gap:* the Builtin backend stood up in 3a is reflection-backed and not
environment-parameterized, so it does **not** satisfy §4.7 — it cannot answer for a
target version or extension the server process lacks. This is **deferred** (Step 5)
and tracked as an open §4.7 gap, not scheduled; the interim behavior (reflection +
optimistic availability) is intentional.

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

*Handler dependency shape.* This does not give handlers a second resolver. Point-query
handlers (Definition / Hover / …) depend on the positional-facing `CodeResolver`
(`resolveAtPosition` and the glue behind it); `SymbolSource` is consumed by that glue
and by the completion sources, **not** by handlers directly. The "handlers are thin
formatters over one resolver" invariant is preserved — the knowledge interface sits
below the glue, not beside the handler.

*The positional layer must itself be decomposed.* It would otherwise inherit most of
`SymbolResolver`'s bulk (node-at-offset, scope, member/call detection, text fallback,
`getImports` / `getNameContext`) and become a renamed god class. The success metric is
**not "`SymbolResolver` is thin" alone**; the positional layer lands as cohesive,
independently testable units (e.g. node locator, scope analyzer, member-access
detector, call-context detector, name-context resolver, text fallback).

### Step 5 — Environment-parameterized built-ins (deferred; tracked §4.7 gap)

*Status:* **deferred.** §4.7 (built-in knowledge parameterized by the project's
target, not the server runtime) is a real requirement, but a correct static, version-
and extension-aware built-in source is a hard problem in its own right, and the
obvious off-the-shelf candidate (JetBrains `phpstorm-stubs`) is **rejected** for known
issues. Rather than block the foundation on it, ship an interim and track the gap.

*Interim (what actually runs — already stood up in Step 3a).* The Builtin backend
uses **process reflection** (`get_defined_functions()` / `ReflectionClass`), and
availability is modeled **optimistically**: every reflected built-in is treated as
available for the target (the availability predicate is hardcoded to true). This is
the zero-index, instant path; it adds no dependency and no ingestion cost.

*Known limitation (the tracked gap).* Reflection describes only what the *server
process* has loaded, so the interim is wrong precisely where §4.7 cares: a target on a
different PHP version, or using an extension the server lacks, sees the server's
built-ins, not the target's. §4.7 is therefore an **open, tracked gap** — file an
issue when this step is scheduled — consistent with the plan's rule that an unmet
invariant is a tracked gap, not a passed step.

*Future (when scheduled).* Replace reflection with a static, version-aware source —
**TBD** — behind the same Builtin backend
interface. Because that interface is stable, this is a **source swap behind a config
flag** (source-swap revert profile, §1): fall back to reflection if the new source
regresses. Only then do the deferred concerns become live — the lazy-first exception
(a static source has no name→file map, so it needs a pre-derived index), the ingestion
cost, the `TargetEnvironment` derivation (`composer.json` `php` + `ext-*`, admitting
explicit config, treating undeclared extensions as unknown), its invalidation
(`composer.json` via `workspace/didChangeWatchedFiles`; config override via
`workspace/didChangeConfiguration`), and retiring / re-pointing the 3b reflection
oracle (which stays valid only while the backend is reflection-backed).

*Acceptance (interim):* the Builtin backend resolves via reflection with an optimistic
availability predicate; the §4.7 gap is recorded as a tracked issue; **no stub
dependency is introduced.**

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

### Teardown ledger

The strangler introduces scaffolding that later steps remove; because that teardown
is distributed across steps, it is enumerated here so nothing is left behind. Each
row is checked by the `review-slice` pass when its *remover* slice lands, and every
row must be discharged at the Definition of Done (Step Z).

    Scaffolding introduced                                    Removed / merged in
    --------------------------------------------------------  --------------------
    Step 2 facade hides today's double write (§5.5)           Step 3a(iv)
    Two ComposerAutoloadMap instances (pre-existing dup)      Step 3a(ii)
    Step 2 §4.2 rule exemption for FunctionRepository         Step 3b
    getFileFunctions (transitional; last caller migrates)     Step 3b
    DefaultFunctionRepository AST-in signature                Step 3b
    SymbolResolver god class                                  Step 4
    TextFallbackHelper breadth (narrow to FQN recovery)       Step 4
    CodeResolver knowledge-facing methods                     Step 4
    A Step 0 standing cache, if built (no orphan)             Step 3a(i)
    WorkspaceIndexer (dead today)                             §3 note (delete unless workspace scope taken)

A scaffold with no discharged remover by Step Z is a defect, not an acceptable end
state.

### Step Z — Definition of Done (final verification gate)

The steps above end the *work*; this gate declares the foundation *complete*. It is a
checklist PR, not a feature step: it produces no new behavior, only the assertion —
verified repo-wide — that the invariants hold and no transitional cruft remains.

*Acceptance (all must hold):*

- **Enforcement is complete.** Every §8.1 enforcement rule is active **repo-wide with
  zero remaining exemptions or allowlists** (the Step 2 §4.2 exemption, and any other,
  are gone). A rule still carrying a scope is an open step, not done.
- **Conformance is repo-wide.** RFC §8's 12-item checklist passes across the whole
  codebase, not merely per change.
- **Parity is trustworthy.** The Step P harness is green **and** its branch coverage of
  the migrated surfaces is adequate — no unexercised surface branch (Step P) — and
  goldens were spot-audited, not just diffed.
- **Teardown is discharged.** Every row of the Teardown ledger is removed; a scan
  confirms no facade-only delegations, no double writes, and no dead transitional
  classes (`DefaultFunctionRepository`, etc.) survive.
- **Divergences are reconciled.** Each entry in RFC 1 Appendix B "current divergences"
  (byte offsets, functions-need-AST, constants-unresolved, capabilities-unread) is
  either **fixed** or **converted to an explicitly-tracked deferred gap with an open
  issue** — none silently persists.
- **Remaining gaps are named.** The only surviving non-conformances are the
  explicitly-deferred, tracked ones, each with an issue: §4.7 / built-ins (Step 5), the
  workspace scope (#264/#265), the diagnostics / scheduler tier (Step 6 / #266), and
  computed-name `define()` (§3). A gap not on this list is a bug, not a deferral.

Only when all six hold is the foundation deemed complete.

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
  kind is implicit and `NameKind` is not passed. `ClassLikeName` is today's
  `ClassName` (which per CLAUDE.md also serves as the class `Type`); whether it is
  reused as-is, renamed, or wrapped is an open decision (§7). The other three are new.
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
regress when `FunctionCandidates` moves to `search` in Step 3b. Note the 100-item cap
(`RESULT_LIMIT`) is applied **centrally in `CompletionHandler` after ranking across
all sources** (`CompletionHandler.php:125-137`), not on a source's output — so a
per-candidate follow-up `lookupFunction` would run over the *uncapped* function-search
result, not a bounded 100. That tilts the decision toward a lazy
`completionItem/resolve` capability (ties to Step 1 negotiation) that fills detail
only for the items the client actually renders — the LSP-idiomatic answer — over an
eager per-candidate lookup. Resolve the choice in Step 3b; keep `search` results
lightweight regardless.

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
        // Step 3a: collapse to a single parse + single write path (structures may stay distinct).
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

Step 2 ──► Step 3a ──► Step 3b
                  └────► Step 4    (see caveat)
Step 5 (deferred — tracked §4.7 gap) and Step 6 (deferred — needs #266): later, independent
```

- **Step P gates Steps 2–4** — the behavior-preserving claims are unprovable without
  it, so it lands first (it can be built in parallel with Steps 0/1).
- **Steps 3 and 4 both edit `SymbolResolver`** — Step 3b rewrites its function/
  constant lookups; Step 4 extracts its positional layer. These are not freely
  parallel. Serialize the `SymbolResolver`-editing slices: do Step 3b's lookup
  migration first, then Step 4's extraction (or vice versa), but not concurrently.
  Step 4's `TypeClassifier` and interface-split slices are independent of Step 3 and
  may proceed alongside.
- **Step 3a is itself a cluster of small PRs** (cache abstraction, `ComposerAutoloadMap`
  dedupe, backend composite, write-path collapse), not one commit — per the project's
  small-commit rule. A Step 0 standing cache, if the spike warrants it, rides in on
  the cache-abstraction PR.
- **Step Z (Definition of Done)** is terminal: it runs after every other step, with
  the Teardown ledger fully discharged, and declares the foundation complete.

Recommended first PR: Step 0's spike, with Step P and Step 1 in parallel; Step 2
once Step P is green.

## 7. Open decisions (resolve at implementation time)

- The perceptibility threshold and cache decision from the Step 0 spike.
- Whether `ClassLikeName` is the existing `ClassName` reused as-is, renamed, or a
  wrapper — it must coexist with `ClassName`'s dual role as the class `Type` (§5.3).
- Whether `FunctionName` / `ConstantName` / `NamespaceName` land in Step 2 as prep
  or in Step 3 with their lookups (lean: Step 3, to avoid an unused-type commit;
  `NamespaceName` is needed by `childrenOf` in Step 2, so it lands then).
- Whether completion detail after the `search` migration comes from a follow-up
  `lookupFunction` or a `completionItem/resolve` capability (§5.4).
- Whether the Workspace backend is lazy-only or gains bounded background indexing
  (only relevant if the workspace scope is taken up).
- The future version-aware built-in source for Step 5 — **TBD, explicitly not
  `phpstorm-stubs`** (known issues) — and, when chosen, its ingestion / derivation
  model and the `TargetEnvironment` / undeclared-extension policy. Until then the
  interim is reflection + optimistic availability, and §4.7 is a tracked gap.
- The external-change invalidation fallback when the client does not support
  `didChangeWatchedFiles` (lazy re-read vs. no invalidation) (Step 3).
