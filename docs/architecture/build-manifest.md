# Build Manifest (slice registry)

    Status:   Draft — seeded through Wave 2 (Steps 3, 4, Z; Steps 5, 6 deferred)
    Driver:   build-procedure.md
    Plan:     0002-execution-plan.md

This is a **static** registry of build slices. It records *what* the slices are and
how they depend on each other; it does **not** record progress — a slice's status is
computed from whether its PR (`slice/<id>`) is merged (see `build-procedure.md`).

Append later phases as they are reached; do not create the whole tree up front.

## Columns

- **ID** — stable slice id; the branch is `slice/<ID>`. An id is assigned when the slice is
  filed and never changes, because a merged slice is found by it. It therefore does **not**
  imply execution order: order is this table's row order plus `Depends on`, so a row can be
  inserted anywhere without renumbering merged work.
- **Step** — the plan step in 0002 that owns the acceptance criteria.
- **Depends on** — slice ids that must be `done` (merged) first. Two collective forms
  exist so a gate's dependencies cannot go stale as slices are added: **`all Step N`**
  means every *other* slice whose Step column is `N` (sub-steps included, so `all Step
  3` covers 3a and 3b), and **`all prior`** means every other slice in the table.
  Gates use these; ordinary slices name ids.
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
Definition-of-Done gate. Steps 3 and 4 each end with a duplication audit (S3.11,
S4.7), which Step Z re-runs repo-wide as its completion gate.

    ID     Step  Title                                              Depends on        Closes
    -----  ----  -------------------------------------------------  ----------------  -------
    S3.1   3a    Existing caches -> replaceable §5.3 seam (verify)  S2.6              —
    S3.2   3a    Dedupe the duplicate ComposerAutoloadMap           S2.6              —
    S3.3   3a    Named backends + fixed-precedence composite        S3.1,S3.2         —
    S3.4   3a    One parse / one write path + consistency check     S3.3              —
    S3.5   3a    External-file-change invalidation                  S3.3,S3.4         —
    S3.6   3b    Function-surface golden + Builtin enum oracle      S3.3              —
    S3.7a  3b    Read autoload.files into ComposerAutoloadMap       S3.3              —
    S3.7b  3b    Scan a file for the declarations it makes          S3.3              —
    S3.7c  3b    ClassLocator -> kind-agnostic SymbolLocator        S3.3,SC.4         —
    S3.7d  3b    Derived autoload.files index, for all three kinds  S3.7a,S3.7b,S3.7c —
    S3.7e  3b    Enumerate the derived index in childrenOf          S3.7d             —
    S3.8a  3b    lookupFunction project reach                       S3.6,S3.7d        —
    SC.2   —     Retire ScopeFinder's superseded import extraction  —                 —
    SC.5   —     One declaration finder, not five hand-written      —                 —
    SC.9   —     Class-like registration -> one declaration scan   —                 —
    SC.6   —     Symbol-name keys -> NameKind::normalize           —                 —
    S3.8d  3b    Collapse per-kind lookup to one call               SC.5              —
    S3.8b  3b    lookupConstant project reach                       S3.7d,SC.2,SC.6,S3.8d  —
    S3.8c  3b    Retire the AST-in function lookup from consumers   S3.8a,SC.5        —
    S3.9a  3b    Generalize search to a kind parameter              S3.8a,S3.8d       —
    S3.9b  3b    Function search + FunctionCandidates migration     S3.9a             —
    S3.10  3b    Remove §4.2 fn-path exemption; retire scaffolding  S3.8b,S3.8c,S3.9b —
    S3.11  3     Step 3 duplication audit                          all Step 3        —
    S4.1   4     TypeClassifier + §4.5/§4.6 static rules            S2.6              —
    S4.2   4     Extract node locator + scope analyzer              S3.8c,S4.1        —
    S4.3   4     Extract member-access + call-context detectors     S4.2              —
    S4.4   4     Extract name-context resolver                      S4.2              —
    S4.5   4     Narrow TextFallbackHelper to FQN recovery          S4.3,S4.4         —
    S4.6   4     SymbolResolver -> glue; CodeResolver positional    S4.2,S4.3,S4.4,S4.5  —
    S4.7   4     Step 4 duplication audit                          all Step 4        —
    SC.1   —     Delete the dead WorkspaceIndexer                    —                 —
    SC.3   —     Namespace tracking -> the parser's namespacedName   —                 —
    SC.4   —     Dedupe the hand-rolled file:// conversion           —                 —
    SC.7   —     Six member-hierarchy walks -> one                   —                 —
    SC.8   —     Prefix matching: SymbolIndex -> PrefixMatcher       —                 —
    SZ.1   Z     Definition of Done gate + repo-wide dup audit      all prior         —

Notes:

- **S3.1 is verify-then-seam.** The Step 0 spike (0002 §8.5 decision 2) declined a
  standing parse cache; it is *not* built here. S3.1 moves the two existing hand-rolled
  memoizations — `DefaultClassRepository::$cache` (ClassInfo by FQN) and
  `CachedNamespaceCatalog::$cache` (stable-source `childrenOf`) — behind the §5.3
  replaceable seam, and each cache kept must demonstrably drop a parse / source call on
  a hit (asserted via `ParseMetrics`); one that cannot is removed, not wrapped.
- **S3.7 is four slices, cut where Composer's own data divides.** A single slice was
  built first (PR #388, 622 src lines over 17 files) and was too large to review as
  one unit. The seam it missed is already in the code: a lookup against the autoload
  maps is arithmetic on the name (`findFile`, five lines — the old
  `ComposerClassLocator` verbatim), while anything an `autoload.files` entry declares
  has no name→file map of any kind and must derive one by parsing that set. The cut
  is therefore by *route*, not by symbol kind — class-likes use both routes. So
  **S3.7c generalizes the shape** — the
  kind-agnostic `SymbolLocator` interface, `QualifiedName`, and the class-like branch
  — which is behavior-preserving and proven by the existing class-like-lookup golden;
  **S3.7d adds the reach**, which is new behavior proven by new fixtures. S3.7a and
  S3.7b are the two independent inputs S3.7d consumes (the autoload map's `files`
  section; the per-file declaration scan) and have no dependency on each other.
  - **S3.7e closes the surface, not just the lookup.** S3.7d makes a `files`-declared
    name resolvable by `lookupClassLike`, but `childrenOf` still enumerates by listing
    a PSR-4 directory, which these files sit outside — so the name resolves on hover
    and definition while never appearing in namespace completion. That split is the
    inconsistency this series exists to prevent (#190, #253, #256), and it is why the
    gap is a slice rather than a documented limitation. The data is already there: the
    derived index knows every name each entry declares, so this is wiring enumeration
    onto it, not a second scan. Found while reviewing S3.7d, which delivered the lookup
    half only.
- **S3.8d collapses `SymbolBackend`'s per-kind methods to one.** The split is settled in
  0002 §5.6: the public `SymbolSource` keeps a typed method per kind (§5.1 requires
  concrete return types), the backends behind it take one kind-parameterized lookup, so a
  new kind is never a change to every backend. `FilesystemBackend` and `BuiltinBackend`
  each carry the same twenty lines twice today and `OpenDocumentBackend` two parallel array
  pairs; after SC.5 those differ only in which factory builds the metadata. S3.8d also
  carries the §8.1 mechanism for §5.1 (see 0002), per the rule that a seam ships with its
  enforcement.
  - **S3.8b is the proof.** Its acceptance carries one criterion that cannot be met by
    appearance: **its diff must touch no `SymbolBackend` implementation.** If it does,
    S3.8d did not work.
- **S3.8 and S3.9 are cut by symbol namespace, not by layer.** A layer cut (interface,
  then backends, then consumers) would land `SymbolBackend` methods no backend
  implements, so each slice is instead one vertical: a kind's name type, its
  `SymbolSource` method, its extraction, and its tests — **not** a `SymbolBackend` method
  per kind, which S3.8d removes. S3.8c
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
  Step 2 carries `ClassLikeName` / `NamespaceName`; `QualifiedName` lands in **S3.7b**,
  whose `DeclarationScanner` is its first caller; `FunctionName` in **S3.8a** and
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
  - **SC.2, SC.5 and SC.6 sit up in the Step 3b rows, not here.** Each is a live defect
    rather than a redundancy, and row order is what `/do-next` uses to break ties between
    unblocked slices — in this block they would be picked after S4.1, leaving wrong
    completion results standing for another slice or two. Keep them ahead of S3.8b; their
    notes stay below with the rest of the `SC.*` explanations.

    Their Step column stays `—`, so `all Step 3` does not expand to include them and S3.11
    is not gated on them. That is deliberate: they are not Step 3's work, they merely run
    during it. `all prior` does reach them, so SZ.1 still requires them.

    S3.8b omits SC.5 from its `Depends on` because S3.8d already carries it; the two rows
    sit adjacent so the chain is visible. Restore it if S3.8d's dependencies ever change.
  - **SC.1 and SC.3 are unblocked and unbuilt.** SC.3 narrows once SC.5 lands, which
    removes its `FilesystemBackend::findClassInAst` half and leaves `SymbolExtractor` —
    where the FQN is rebuilt by string concatenation in three places, against a
    `namespacedName` the parser already computed and four other sites already read.
  - **Duplication that Step 4 will touch anyway is not filed here.** It is recorded as
    acceptance criteria on the S4.2 / S4.4 / S4.5 rows in 0002 instead, so the
    decomposition cannot be declared done while it survives. Filing it twice would put a
    slice and a step in competition for the same edit.
  - **SC.1** — `Index/WorkspaceIndexer` has zero references in `src/` or `tests/`. It was
    previously a ledger row whose remover was a *§3 note*, which no slice could discharge.
  - **SC.2** — `ScopeFinder::extractImports` / `resolveFromUseStatements` were superseded
    by `NameContextFactory` and their own docblock says they go away once #331 moves the
    callers. #331 landed (#337); three callers did not move (`SymbolResolver` ×2,
    `TextFallbackHelper` ×1). **Ungated, and ahead of S3.8b: this is a live defect, not a
    tidy-up.** `extractImports` folds `use function` and `use const` into the class import
    map, so a class and a function sharing a short name collide — and the constant table it
    also folds in is exactly what constant resolution will read. `NameContext::importsFor()`
    already keeps the three tables apart, so no caller needs Step 4 to move.
  - **SC.3** — `SymbolExtractor` hand-tracks
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
  - **SC.5** — **Five** sites ask "which node declares this name", against
    `Index\DeclarationScanner`, which already answers the general form:
    `DefaultFunctionRepository::findFunctionInAst`, `FilesystemBackend::parseFunctionFrom`,
    `FilesystemBackend::findClassInAst`, `DocumentSymbolSink::functionsIn`, and
    `SymbolResolver::getFileFunctions`. What the backends need beyond the scanner is the
    declaring *node*, so metadata can be built; that is the one thing to add.

    **The five disagree**, so this is a defect and not a tidy-up: `getFileFunctions` feeds
    completion and iterates top-level statements only, while `parseFunctionFrom` and
    `functionsIn` walk all depths, so a `function_exists`-guarded polyfill resolves on
    hover and never appears in completion. That is a §4.2 violation in the code, which is
    why it precedes S3.8b and S3.8c.

    Ungated. It *removes* the `findClassInAst` half of SC.3, narrowing that slice to
    `SymbolExtractor` alone. Deliberately not gated on S3.11: an audit files slices, and a
    slice already filed must not wait on the audit that would have found it.
  - **SC.9** — the class-like half of SC.5, left standing because SC.5's row names five
    sites and this is a sixth. `DocumentSymbolSink::classesIn` hand-walks
    `ScopeFinder::iterateTopLevelStatements` and registers **top-level class-likes only**,
    while its sibling `functionsIn` reads `DeclarationScanner` at any depth. So a
    `class_exists`-guarded class in an open buffer is returned by that backend's
    `searchClassLikes` (which reads `SymbolExtractor`, and does walk all depths) but not by
    its `lookupClassLike` — the §4.2 lookup/enumeration split, exactly as the polyfill
    defect was, on the other symbol namespace. `assertStoresAgree`'s own docblock already
    concedes it ("the index is a superset ... which the top-level lookup registration does
    not").

    A live defect, so it sits with SC.5 rather than in the redundancy block below. Nothing
    pins the depth behaviour in either direction: switching `classesIn` to the scanner
    leaves the suite green, so the slice owes a regression test — a `class_exists`-guarded
    class resolves through `lookupClassLike` on an open document. It is the last consumer
    of `iterateTopLevelStatements` in `src/`, which retires with it.
  - **SC.6** — every key derived from a symbol name routes through
    `NameKind::normalize()`, which owns PHP's per-kind case rule. A whole-FQN `strtolower`
    is hand-rolled instead in `CompositeSymbolSource` (the `searchClassLikes` merge, and
    the subtype walk's visited set), in `NamespaceContents` (`merge` and
    `indexByNamespace`), and in `SymbolIndex`. `OpenDocumentBackend` already routes through
    `normalize`, with a comment noting that whole-FQN lowercasing is right for class-likes
    and functions and wrong for a constant.

    Constants are case-sensitive, so `Foo\BAR` and `Foo\bar` collapse — **already, not as a
    consequence of S3.8b**: `CatalogSymbol` carries the `NameKind` these sites discard, and
    S3.7e enumerates constants from the derived `autoload.files` index. The row is the case
    rule everywhere rather than one class, so the collapse cannot survive on the
    enumeration path for S3.8b to land on top of. Ahead of S3.8b for that reason.

    Also here: `FilesystemBackend::parseClassFrom` compares raw fully-qualified names,
    while `lookupClassLike` keys its cache through `SymbolCacheKey` — which *does*
    normalize — and the sibling `parseFunctionFrom` normalizes both sides. So a wrong-case
    class lookup misses on a cold cache and hits once a correct-case lookup has warmed the
    same key, and `OpenDocumentBackend` answers a name this backend does not. Predates SC.5
    (the deleted `findClassInAst` compared raw strings too) and is unpinned in either
    direction, so the fix owes a test.
  - **SC.7** — `MemberResolver` has six near-identical hierarchy walks:
    `find{Method,Property,Constant}InHierarchy` and `collect{Methods,Properties,Constants}`,
    each a seen-check, a scan of the class's own members, and a recursion over
    `supertypes()`. #334 centralised the type-graph *edges*, and `supertypes()` is correct
    and stays; the *walk* over them was never centralised, so a new member kind adds two
    more copies. Outside Step 4's scope — that step decomposes `src/Resolution/`, this is
    `src/Repository/`.
  - **SC.8** — `Completion\PrefixMatcher::matches` and `SymbolIndex::findByPrefix` both
    hand-roll `str_starts_with(strtolower(...))`. SC.6 owns the `strtolower` half (it is
    the same per-kind case rule); what is left here is the duplicated *matching* helper, so
    take SC.6 first or the two rows fight over the same line.
- **Each section ends with a duplication audit** (**S3.11**, **S4.7**), and Step Z's
  acceptance carries the repo-wide one. Method, scope and outcome rule are defined once
  in 0002 §Duplication audits and the terminal condition is a Step Z acceptance item —
  not restated here, because a manifest note is not a gate.
  - S3.11 and S4.7 are **tracking** gates: a finding may be handed to a new slice
    rather than fixed in place, so a removal belonging to a later section is not dragged
    forward. Step Z is the **completion** gate, where an unowned or unfixed duplicate
    fails outright.
  - The three depend on their whole section (`all Step N` / `all prior`) rather than on
    its last slice. A chain of ids is both stale-prone and wrong: S3.10's transitive
    dependencies never reach S3.4 or S3.5, so a Step 3 audit gated on S3.10 could have
    run with two of its slices unbuilt.
- **The Builtin backend stood up in S3.3 is reflection-backed and does not satisfy
  §4.7** (0002 §5 known gap) — file the tracked §4.7 issue when S3.3 lands; its fix is
  the deferred Step 5.
- **`Closes` is assigned at slice-issue creation, after a reviewer reads the issue —
  never inferred.** Candidates from Wave 1's note: #239 / #181 / #317 land somewhere in
  S3.7a–S3.10; #295 (Visibility enum) wants a small cleanup slice not yet placed.
  - **#181 covers all three kinds**, which is now what S3.7b–S3.7d build: the
    `files` set has no name→file map of any kind, so it is scanned whole. A slice
    claiming #181 must show class-like reach, not just functions and constants.
    - **#181 is not closable before S3.9b.** Its acceptance asks for these symbols in
      *hover and completion*, not merely resolvable: functions need S3.8a and S3.9b,
      constants S3.8b, namespace completion S3.7e. It also asks for a startup-time
      benchmark, which S3.7d's eager index makes a live question rather than a
      formality — S3.7d pins the parse *count*, which is not the same measurement.
      Read the issue body before wiring `Closes`; the reach landing is not the
      criteria being met.

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
