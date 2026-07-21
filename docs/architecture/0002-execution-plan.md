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
    // --- Step 2: the surface today's migrated features actually need ---
    public function lookupClassLike(ClassLikeName $name): ?ClassInfo;          // exact name -> full info (hover, def, members)
    /** @return list<SymbolDefinition> */
    public function searchClassLikes(string $prefix): array;                   // prefix fragment -> candidates (class completion)
    public function childrenOf(NamespaceName $namespace): NamespaceContents;    // enumeration (namespace completion)

    // --- Added JIT, when the step that needs them lands (NOT built in Step 2) ---
    // Step 3b (functions/constants gain project reach; a second searchable kind exists):
    //   lookupFunction(FunctionName): ?FunctionInfo
    //   lookupConstant(ConstantName): ?ConstantInfo
    //   searchClassLikes generalizes to search(string $prefix, NameKind $kind)
    // Future (workspace scope, #264):
    //   locate(QualifiedName, NameKind): ?SymbolDefinition   // kind-neutral def-site, only if a feature needs it
    //   project-wide / cross-file search (workspace/symbol)
}

interface SymbolSink
{
    public function openDocument(TextDocument $document): void;
    public function updateDocument(TextDocument $document): void;
    public function closeDocument(string $uri): void;
}
```

**JIT the interface (should this be front-loaded? — no).** The interface grows with
the features, like everything else in the plan. Step 2 carries only what the migrated
features need — exact class-like lookup, class-like prefix search, namespace
enumeration — plus the `SymbolSink` writes. `lookupFunction` / `lookupConstant` arrive
in Step 3b; a kind-parameterized `search` arrives with them (a `NameKind` argument is
meaningless while only class-likes are searchable); `locate` and a cross-file `search`
arrive with the workspace scope. A method with no current caller is not carried.

The three verbs, and why two defer: **`lookup*`** = exact name → full typed info
(`ClassInfo`); **`search*`** = prefix *fragment* → lightweight candidates; **`locate`**
= exact name → just a definition site, kind-neutral. `lookup` and `search` are clearly
distinct (exact vs. prefix; full info vs. candidates) and both serve current
completion / hover / def. `locate` overlaps `lookup` — go-to-definition can already use
`lookupClassLike($name)?->getDefinitionLocation()` — so it earns a slot only if a later
feature needs a def-site *without* building full info, or a kind-neutral workspace
entry. Deferring it removes the naming ambiguity and lets it be named when a concrete
need defines it.

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
  is about identifiers, not search fragments. `kind` (once the parameter exists in
  Step 3b) selects which namespace to search.
- **JIT:** Step 2 uses only `ClassLikeName` (today's `ClassName`) and `NamespaceName`.
  `QualifiedName`, `NameKind`, `FunctionName`, and `ConstantName` land with the methods
  that first use them (Step 3b, and `locate` in the workspace scope) — an unused type
  is not carried ahead of its method. This whole model is the *target*; it is
  introduced piecewise.

### 5.4. `SymbolDefinition` is lightweight; detail comes from a lookup

`searchClassLikes` — and the later `search` / `locate` — return a `SymbolDefinition`
= **identity + kind + location**,
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
        private SymbolIndex $index,              // searchClassLikes → findByPrefix()
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
| `ClassCandidates` | `SymbolIndex` | `SymbolSource::searchClassLikes` | identical (same backing) |
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

- ~~The perceptibility threshold and cache decision from the Step 0 spike.~~
  **Resolved — see Section 8.**
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

## 8. Step 0 spike record (measured)

Measured on the slice `S0.1` instrumentation (`ParseMetrics`, which meters every
`ParserService::parse()` — parse plus both visitor passes). Conditions: PHP 8.5.4
CLI, macOS/arm64, no xdebug, opcache CLI off, and **`pcov.enabled=0`**. Timings
varied ~10-15% run to run; **parse counts were exactly reproducible**, and the
counts are what the decision turns on.

`pcov.enabled=0` is a deliberate choice of baseline, not a description of how the
server runs. `bin/php-lsp` is `#!/usr/bin/env php`, so it inherits whatever the
development machine's CLI ini says — and on the machine these numbers were taken
from, `conf.d/pcov.ini` sets `pcov.enabled=1`, which roughly doubles every timing
below. The clean baseline is the right one to derive an architectural decision
from, but it means §8.4's thresholds are *ceilings on file size under ideal
conditions*: with pcov left on, the same budgets are exceeded at roughly half the
line counts. That does not change the decision — it only widens the margin by
which the cost is already perceptible.

### 8.1 Cost of one parse

Real files, 25 iterations each, mean. Line counts are `wc -l` plus one, so they
count the final line rather than the newlines; §8.3 adds one more for the line the
completion prefix is typed on.

| Tier | Source | Lines | Bytes | Mean ms | Retained AST bytes | AST : source |
|---|---|---|---|---|---|---|
| small | `tests/Fixtures/src/Domain/User.php` | 320 | 7,373 | 1.8 | 382,576 | 52× |
| medium | `src/Repository/DefaultClassInfoFactory.php` | 712 | 24,225 | 5.7 | 1,300,432 | 54× |
| large | `src/Resolution/SymbolResolver.php` | 1,702 | 58,870 | 14.6 | 2,989,808 | 51× |
| pathological | `vendor/squizlabs/php_codesniffer/src/Files/File.php` | 2,947 | 107,557 | 23.2 | 5,038,912 | 47× |
| generated | `vendor/nikic/php-parser/lib/PhpParser/Parser/Php8.php` | 2,918 | 193,747 | 120.6 | 24,960,120 | 129× |

Parse time tracks bytes, not lines, at roughly **4 MB/s**. A retained AST costs
about **50× its source size** — the figure any standing cache must budget against
(the generated-parser row is an outlier: dense table literals, not typical code).

### 8.2 Reparse count per request

Counted after `didOpen`, on the existing fixture corpus. Two columns, because the
count depends on whether the class repository's `ClassInfo` memo is warm for the
types the request touches: **first touch** is the same request issued against a
cold repository, **steady state** is the immediately repeated identical request.

| Request | First touch | Steady state |
|---|---|---|
| `didOpen` / `didChange` notification | 2 | 2 |
| completion — member access (`$this->`) | 1 | 1 |
| completion — variable (`$x`) | 3 | 3 |
| completion — bare identifier prefix | 5 | 5 |
| completion — after `new` | 5 | 5 |
| hover on a method of a workspace class | 2 | 1 |
| signatureHelp on a workspace class' method | 2 | 1 |
| definition through a trait | 4 | 1 |

Two distinct costs are visible here, and they behave differently.

The **steady-state** column is the `SymbolResolver` fan-out. Its seven
`parser->parse()` sites do not compound on the point-query paths: hover,
definition, and signatureHelp each take one code path and parse the open document
once. They compound on **completion**, which fans out to several sources — each
calling a different `CodeResolver` method (`getMemberAccessContext`,
`getVariablesInScope`, `getImports`, `getNameContext`, `getFileFunctions`), each of
which re-parses the same unchanged document. The sync notification adds two more:
`TextDocumentSyncHandler::indexDocument()` parses, then hands the document to
`DocumentIndexer`, which parses it again. So one keystroke in a completion context
is **7 parses of identical content**, every time.

The **gap between the columns** is a second cost entirely:
`DefaultClassRepository::locateAndParse()` parses one file per supertype it must
resolve from disk. Definition through a trait costs 4 rather than 1 because `User
implements Entity, Person` and uses `HasTimestamps`. These are parses of
*different* documents, so request-scoped dedup does not remove them — and does not
need to: they are memoized by FQN across requests, so each is a one-off per class.

The decision below turns on the steady-state column, because that is the cost that
never warms. The first-touch column is recorded so that a later reader does not
mistake a cold-start measurement for a regression.

One caveat on the completion rows: they do not vary between the columns today only
because `SymbolIndex` holds open documents alone — `WorkspaceIndexer` is not wired
into `Server.php`. Class-position completion resolves every candidate through the
repository, so if a workspace index later feeds it, its first-touch column grows
with the index while its steady state does not.

### 8.3 Cost in the round: one keystroke

A bare-prefix completion typed at the end of a real file — `didChange` followed by
`textDocument/completion`, as a client actually sends it:

| Tier | Lines | Completion alone (5 parses) | Full keystroke (7 parses) |
|---|---|---|---|
| small | 321 | 9-14 ms | 13-14 ms |
| medium | 713 | 29-32 ms | 45-90 ms |
| large | 1,703 | 76-81 ms | 120-127 ms |
| pathological | 2,948 | 130-132 ms | 187-193 ms |

### 8.4 Perceptibility threshold

**Threshold: 50 ms per request, 100 ms per keystroke.** Below ~100 ms an
interaction reads as instantaneous; a completion popup that lands later than that
is visibly chasing the typist, and at typing speed the work also queues. 50 ms per
request is the working half of that budget, leaving room for the non-parse work.

Measured against it, and solving the fitted cost (5 or 7 parses plus the ~5 ms and
~23 ms of non-parse work observed at the large tier) for the budget: the
per-request budget is exceeded from roughly **1,100 lines** of open document, and
the per-keystroke budget from roughly **1,300 lines**. Both are ordinary file sizes
— this repository's own `SymbolResolver.php` (1,702 lines) is past both, and its
measured keystroke cost, 120-127 ms, is above the threshold outright. The cost is
therefore **not** imperceptible, and the "if imperceptible, do nothing" branch of
Step 0 does not apply.

### 8.5 Decision

1. **Ship request-scoped dedup (S0.2).** It cuts the keystroke from 7 parses to 2
   (one per message) with no invalidation risk whatsoever: within one message the
   document content cannot change, so a memo held for that message's duration and
   then discarded cannot go stale. Projecting the measured parse costs onto that
   count — not itself measured, since dedup is S0.2's to build — the large tier
   falls from ~125 ms to ~50 ms per keystroke and the pathological tier from
   ~190 ms to ~75 ms, i.e. under the threshold at every tier measured. S0.2 should
   confirm this rather than assume it.
2. **Do not add a standing cache now — this overrides the decision rule's cache
   branch, on a projection.** Be explicit about that. §8.4 establishes the
   antecedent of the rule's second branch ("add a standing cache only if large
   files cross a perceptibility threshold"): they do. The rule's literal reading
   would add one. It is not being added because the *first* branch's remedy is
   projected to clear the threshold on its own, which makes the cache's remaining
   value smaller than its cost.

   That remaining value is real but narrow. Dedup leaves one parse per handled
   message; a version-keyed standing cache would leave one per document *version*,
   so it would additionally remove re-parses across messages at an unchanged
   version — the `didOpen` → hover → completion sequence costs three parses under
   dedup and one under a standing cache. Against that: ~50× source size in retained
   AST for every open document, plus invalidation to get right. At the post-dedup
   per-request costs these numbers imply — ~15 ms at the large tier, well inside the
   50 ms budget — that trade is not justified.
3. **Revisit only on evidence.** If a standing cache is later warranted, it lands as
   the Step 3 rider described in Section 6 — open documents only, keyed by document
   version, behind the §5.3 cache abstraction — never as hard-coded memoization.
   What reopens it, specifically: S0.2's measured post-dedup keystroke costs, if
   they do not land under the §8.4 thresholds the way decision 1 projects. Decision
   2 rests on that projection, so S0.2 is the checkpoint that confirms or overturns
   it.

Note that the dedup boundary must cover the **notification** path as well as the
request path: two of the seven parses are `didChange`'s, which `SymbolResolver`
never sees. "Request-scoped" therefore means scoped to one handled LSP message,
notifications included.
