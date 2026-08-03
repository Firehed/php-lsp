# Build Manifest (slice registry)

    Status:   Draft — seeded through Wave 2 (Steps 3, 4, Z; Steps 5, 6 deferred)
    Driver:   build-procedure.md
    Plan:     0002-execution-plan.md

This is a **static** registry of build slices. It records *what* the slices are and
how they depend on each other; it does **not** record progress — a slice's status is
computed from whether its PR (`slice/<id>`) is merged (see `build-procedure.md`).

Append later phases as they are reached; do not create the whole tree up front.

## Columns

- **ID** — stable slice id; the branch is `slice/<ID>`.
- **Step** — the plan step in 0002 that owns the acceptance criteria.
- **Depends on** — slice ids that must be `done` (merged) first.
- **Closes** — pre-existing issues this slice closes, *after reviewer verification*.

## Wave 1 — Steps 0, 1, P, 2

    ID     Step  Title                                              Depends on        Closes
    -----  ----  -------------------------------------------------  ----------------  -------
    S0.1   0     Instrument parse count/time; run the spike         —                 —
    S0.2   0     Request-scoped parse dedup (if spike warrants)     S0.1              —
    S1.1   1     Read ClientCapabilities -> SessionCapabilities     —                 —
    S1.2   1     Negotiate positionEncoding; convert at the edge    S1.1              #192
    S1.3   1     Shape hover markup / snippets via capabilities     S1.1              #22
    S1.4   1     Lifecycle state + malformed-frame robustness       S1.1              —
    S1.5   1     Position round-trip corpus (regression net)        S1.2              —
    SP.1   P     Per-surface parity harness + branch-coverage gate  —                 —
    S2.1   2     Define SymbolSource/SymbolSink + delegating facade SP.1              —
    S2.2   2     Migrate ClassCandidates -> search                  S2.1              —
    S2.3   2     Migrate NamespaceCandidates -> childrenOf          S2.1              —
    S2.4   2     Migrate SymbolResolver class lookups -> lookupClassLike  S2.1        —
    S2.5   2     Migrate TextDocumentSyncHandler -> SymbolSink      S2.1              —
    S2.6   2     §4.2 enforcement rule (scoped-exempt FunctionRepo) S2.2,S2.3,S2.4,S2.5  —

Notes:

- `NamespaceName` typed identifier is needed by S2.3 (`childrenOf`); land it within
  that slice or as its immediate predecessor.
- Steps 0, 1, and P are mutually independent and may run in any order; Step 2 is
  gated on the parity harness (SP.1).
- Wave 2 (Steps 3, 4, Z) is decomposed below, appended now that S2.* is `done`. Steps
  5 and 6 remain deferred (see the Deferred note under Wave 2).

## Wave 2 — Steps 3, 4, Z

Step 3 is one plan step with two halves: **3a** is behavior-preserving (proven by the
Step P harness — parity fixtures first), **3b** both preserves and extends the function
surface (existing behavior frozen to a golden; new project reach proven by new
fixtures). The `Step` column carries the half, since 3a and 3b own distinct acceptance
criteria in 0002. Step 4 decomposes `SymbolResolver`; Step Z is the terminal
Definition-of-Done gate.

    ID     Step  Title                                              Depends on        Closes
    -----  ----  -------------------------------------------------  ----------------  -------
    S3.1   3a    Existing caches -> replaceable §5.3 seam (verify)  S2.6              —
    S3.2   3a    Dedupe the duplicate ComposerAutoloadMap           S2.6              —
    S3.3   3a    Named backends + fixed-precedence composite        S3.1,S3.2         —
    S3.4   3a    One parse / one write path + consistency check     S3.3              —
    S3.5   3a    External-file-change invalidation                  S3.3,S3.4         —
    S3.6   3b    Function-surface golden + Builtin enum oracle      S3.3              —
    S3.7a  3b    Read autoload.files into ComposerAutoloadMap       S3.3              —
    S3.7b  3b    Scan a file for its function/constant declarations S3.3              —
    S3.7c  3b    ClassLocator -> kind-agnostic SymbolLocator        S3.3,SC.4         —
    S3.7d  3b    Derived autoload.files function/constant index     S3.7a,S3.7b,S3.7c —
    S3.8a  3b    lookupFunction project reach                       S3.6,S3.7d        —
    S3.8b  3b    lookupConstant project reach                       S3.7d             —
    S3.8c  3b    Retire the AST-in function lookup from consumers   S3.8a             —
    S3.9a  3b    Generalize search to a kind parameter              S3.8a             —
    S3.9b  3b    Function search + FunctionCandidates migration     S3.9a             —
    S3.10  3b    Remove §4.2 fn-path exemption; retire scaffolding  S3.8b,S3.8c,S3.9b —
    S4.1   4     TypeClassifier + §4.5/§4.6 static rules            S2.6              —
    S4.2   4     Extract node locator + scope analyzer              S3.8c,S4.1        —
    S4.3   4     Extract member-access + call-context detectors     S4.2              —
    S4.4   4     Extract name-context resolver                      S4.2              —
    S4.5   4     Narrow TextFallbackHelper to FQN recovery          S4.3,S4.4         —
    S4.6   4     SymbolResolver -> glue; CodeResolver positional    S4.2,S4.3,S4.4,S4.5  —
    SC.1   —     Delete the dead WorkspaceIndexer                    —                 —
    SC.2   —     Retire ScopeFinder's superseded import extraction   S4.4,S4.5         —
    SC.3   —     Namespace tracking -> the parser's namespacedName   —                 —
    SC.4   —     Dedupe the hand-rolled file:// conversion           —                 —
    SZ.1   Z     Definition of Done gate                            S3.10,S4.6,SC.1,SC.2,SC.3,SC.4  —

Notes:

- **S3.1 is verify-then-seam.** The Step 0 spike (0002 §8.5 decision 2) declined a
  standing parse cache; it is *not* built here. S3.1 moves the two existing hand-rolled
  memoizations — `DefaultClassRepository::$cache` (ClassInfo by FQN) and
  `CachedNamespaceCatalog::$cache` (stable-source `childrenOf`) — behind the §5.3
  replaceable seam, and each cache kept must demonstrably drop a parse / source call on
  a hit (asserted via `ParseMetrics`); one that cannot is removed, not wrapped.
- **S3.7 is four slices, cut where Composer's own data divides.** A single slice was
  built first (PR #388, 622 src lines over 17 files) and was too large to review as
  one unit. The seam it missed is already in the code: a class-like lookup is
  arithmetic on the name (`findFile`, five lines — the old `ComposerClassLocator`
  verbatim), while a function or constant lookup has no name→file map and must derive
  one by parsing the `autoload.files` set. So **S3.7c generalizes the shape** — the
  kind-agnostic `SymbolLocator` interface, `QualifiedName`, and the class-like branch
  — which is behavior-preserving and proven by the existing class-like-lookup golden;
  **S3.7d adds the reach**, which is new behavior proven by new fixtures. S3.7a and
  S3.7b are the two independent inputs S3.7d consumes (the autoload map's `files`
  section; the per-file declaration scan) and have no dependency on each other.
- **S3.8 and S3.9 are cut by symbol namespace, not by layer.** A layer cut (interface,
  then backends, then consumers) would land `SymbolBackend` methods no backend
  implements, so each slice is instead one vertical: a kind's name type, its
  `SymbolSource`/`SymbolBackend` method across all four backends, and its tests. S3.8c
  then migrates the consumers (`SymbolResolver`, `BasicTypeResolver`) off
  `FunctionRepository::get(string, array $ast)` — it is the Step 3b slice that edits
  `SymbolResolver`, so it, not S3.8a, is what S4.2 serializes against (§6).
  S3.9 divides on provability rather than kind: **S3.9a** widens `searchClassLikes` to
  `search(string $prefix, NameKind $kind)` with class-likes still the only searchable
  kind, which is behavior-preserving and leaves every Step P golden frozen; **S3.9b**
  makes the backends answer function search and moves `FunctionCandidates` onto it,
  rewriting only the function-surface golden S3.6 froze. Note `BuiltinBackend` MUST
  answer function search in S3.9b or built-in function completion regresses — that
  golden is what catches it.
- **Name-type model is JIT (§5.3).** Each type lands with its first caller, not ahead
  of it. `NameKind` already exists (it predates Wave 2, as the catalog's coarse kind);
  Step 2 carries `ClassLikeName` / `NamespaceName`; `QualifiedName` lands in **S3.7c**,
  whose `SymbolLocator::locate` is its first caller; `FunctionName` in **S3.8a** and
  `ConstantName` in **S3.8b**, with their lookups.
  - **`ConstantName` is already taken.** `Domain\ConstantName` wraps a *class* constant
    name; §5.3's `ConstantName` is a *global* constant FQN. Decide the naming before
    S3.8b rather than inside it — this is the same coexistence question §7 leaves open
    for `ClassLikeName` versus `ClassName`.
- **Steps 3 and 4 both edit `SymbolResolver` (§6).** S4.2 (positional extraction) is
  gated on S3.8 (the 3b lookup migration) so the two never run concurrently; manifest
  order keeps Step 3 ahead of Step 4 regardless. S4.1 (`TypeClassifier` + the §4.5/§4.6
  rules) is independent of Step 3 and may proceed alongside.
- **Teardown discharge.** S3.2 removes the duplicate `ComposerAutoloadMap`; S3.4 the
  Step 2 double-write facade and (if built) the Step 0 cache rider; S3.10 the §4.2
  function-path exemption, `getFileFunctions`, and the `DefaultFunctionRepository`
  AST-in signature; S4.5/S4.6 the `SymbolResolver` god class, `TextFallbackHelper`
  breadth, and the `CodeResolver` knowledge-facing methods. SZ.1 verifies the ledger is
  fully discharged.
- **The SC.* slices carry no step, on purpose.** They are duplication and dead code that
  predate the plan rather than scaffolding it introduced, so no step's acceptance covers
  them — which is exactly how they went unowned until an audit found them. They are in
  the table so `/do-next` can select them and SZ.1 can require them, not because a step
  produced them. A removal with no owning slice is a defect; put it here.
  - **SC.1** — `Index/WorkspaceIndexer` has zero references in `src/` or `tests/`. It was
    previously a ledger row whose remover was a *§3 note*, which no slice could discharge.
  - **SC.2** — `ScopeFinder::extractImports` / `resolveFromUseStatements` were superseded
    by `NameContextFactory` and their own docblock says they go away once #331 moves the
    callers. #331 landed (#337); three callers did not move (`SymbolResolver` ×2,
    `TextFallbackHelper` ×1). Gated on S4.4/S4.5, which rewrite exactly those sites.
  - **SC.3** — `SymbolExtractor` and `FilesystemBackend::findClassInAst` each hand-track
    `Stmt\Namespace_` to build FQNs that `NameResolver` already computed into
    `namespacedName` (which `DefaultClassInfoFactory`, `DefaultFunctionRepository`,
    `ScopeFinder`, and `DeclarationScanner` all read). Behavior-preserving, so the Step P
    write-path and class-like-lookup goldens prove it. `SymbolExtractor`'s `Class::method`
    FQNs are its own and stay.
  - **SC.4** — `file://` URI and path conversion is hand-rolled in four live places
    (`DefaultClassInfoFactory`, `FilesystemBackend` ×2, `Location`, and the dead
    `WorkspaceIndexer`), each differing in how it handles the scheme and percent-
    encoding. One `FileUri` replaces them. Found while splitting S3.7, whose locator
    wanted a fifth copy; the duplication predates that slice and belongs to no step,
    so S3.7c is gated on it rather than carrying it.
- **The Builtin backend stood up in S3.3 is reflection-backed and does not satisfy
  §4.7** (0002 §5 known gap) — file the tracked §4.7 issue when S3.3 lands; its fix is
  the deferred Step 5.
- **`Closes` is assigned at slice-issue creation, after a reviewer reads the issue —
  never inferred.** Candidates from Wave 1's note: #239 / #181 / #317 land somewhere in
  S3.7a–S3.10; #295 (Visibility enum) wants a small cleanup slice not yet placed.

### Deferred (not scheduled; excluded from `/do-next` until reached)

Kept out of the table above on purpose: a `todo` row with satisfiable dependencies is
pickable, and neither of these should be picked yet. They are appended as real slices
when their phase is reached.

- **Step 5 — environment-parameterized built-ins (§4.7).** Not plannable: its
  version-aware source is an open `TBD` (0002 §7, explicitly not `phpstorm-stubs`). The
  interim reflection Builtin backend already ships in S3.3; §4.7 stays a tracked issue.
  Nothing in Wave 2 depends on Step 5, and SZ.1 permits it to remain a named gap.
- **Step 6 — scheduler / async tier (#266).** Deferred until a push feature needs it.
  Sketch, from its acceptance: `$/cancelRequest` cancellation of superseded work;
  debounced `publishDiagnostics` on change; a background scheduler that does not starve
  interactive requests and is cancelable, feature-detecting `pcntl` / `ext-parallel`
  with a synchronous fallback. Appended as slices when a push feature (or #266) takes
  it up.
