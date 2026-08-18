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
  filed and never changes, because a merged slice is found by it. It carries **no** ordering
  meaning, and neither does row order, which records when a row was filed: what may start is
  `Depends on`, and what starts first is `Kind`. So a row can be inserted anywhere without
  renumbering merged work.
- **Step** — the plan step in 0002 that owns the acceptance criteria.
- **Kind** — what the row is, which is what the driver ranks the startable rows by:
  **`defect`** (behavior is wrong today — two features already disagree), **`cleanup`**
  (duplication or dead code that predates the plan; no feature is wrong yet), **`scaffold`**
  (the plan's own construction work — every row with a Step). Ranked `defect`, then
  `cleanup`, then `scaffold`, so the unblocked cleanup is front-loaded and the guardrail
  baselines drain before the rework's feature-adjacent reach lands on top of them.
- **Depends on** — slice ids that must be `done` (merged) first. Two collective forms
  exist so a gate's dependencies cannot go stale as slices are added: **`all Step N`**
  means every *other* slice whose Step column is `N` (sub-steps included, so `all Step
  3` covers 3a and 3b), and **`all prior`** means every other slice in the table.
  Gates use these; ordinary slices name ids.
- **Closes** — pre-existing issues this slice closes, *after reviewer verification*.

## Wave 1 — Steps 0, 1, P, 2

    ID     Step  Kind      Title                                              Depends on        Closes
    -----  ----  --------  -------------------------------------------------  ----------------  -------
    S0.1   0     scaffold  Instrument parse count/time; run the spike         —                 —
    S0.2   0     scaffold  Request-scoped parse dedup (if spike warrants)     S0.1              —
    S1.1   1     scaffold  Read ClientCapabilities -> SessionCapabilities     —                 —
    S1.2   1     scaffold  Negotiate positionEncoding; convert at the edge    S1.1              #192
    S1.3   1     scaffold  Shape hover markup / snippets via capabilities     S1.1              #22
    S1.4   1     scaffold  Lifecycle state + malformed-frame robustness       S1.1              —
    S1.5   1     scaffold  Position round-trip corpus (regression net)        S1.2              —
    SP.1   P     scaffold  Per-surface parity harness + branch-coverage gate  —                 —
    S2.1   2     scaffold  Define SymbolSource/SymbolSink + delegating facade SP.1              —
    S2.2   2     scaffold  Migrate ClassCandidates -> search                  S2.1              —
    S2.3   2     scaffold  Migrate NamespaceCandidates -> childrenOf          S2.1              —
    S2.4   2     scaffold  Migrate SymbolResolver class lookups -> lookupClassLike  S2.1        —
    S2.5   2     scaffold  Migrate TextDocumentSyncHandler -> SymbolSink      S2.1              —
    S2.6   2     scaffold  §4.2 enforcement rule (scoped-exempt FunctionRepo) S2.2,S2.3,S2.4,S2.5  —

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
criteria in 0002. Step 4 drains the Resolution/TypeInference guardrail-baseline
entries (0002, reframed 2026-08-10); Step Z is the terminal Definition-of-Done
gate. Steps 3 and 4 each end with a duplication audit (S3.11, S4.7), which Step Z
re-runs repo-wide as its completion gate.

    ID     Step  Kind      Title                                              Depends on        Closes
    -----  ----  --------  -------------------------------------------------  ----------------  -------
    S3.1   3a    scaffold  Existing caches -> replaceable §5.3 seam (verify)  S2.6              —
    S3.2   3a    scaffold  Dedupe the duplicate ComposerAutoloadMap           S2.6              —
    S3.3   3a    scaffold  Named backends + fixed-precedence composite        S3.1,S3.2         —
    S3.4   3a    scaffold  One parse / one write path + consistency check     S3.3              —
    S3.5   3a    scaffold  External-file-change invalidation                  S3.3,S3.4         —
    S3.6   3b    scaffold  Function-surface golden + Builtin enum oracle      S3.3              —
    S3.7a  3b    scaffold  Read autoload.files into ComposerAutoloadMap       S3.3              —
    S3.7b  3b    scaffold  Scan a file for the declarations it makes          S3.3              —
    S3.7c  3b    scaffold  ClassLocator -> kind-agnostic SymbolLocator        S3.3,SC.4         —
    S3.7d  3b    scaffold  Derived autoload.files index, for all three kinds  S3.7a,S3.7b,S3.7c —
    S3.7e  3b    scaffold  Enumerate the derived index in childrenOf          S3.7d             —
    S3.8a  3b    scaffold  lookupFunction project reach                       S3.6,S3.7d        —
    SC.2   —     defect    Retire ScopeFinder's superseded import extraction  —                 —
    SC.5   —     defect    One declaration finder, not five hand-written      —                 —
    SC.9   —     defect    Class-like registration -> one declaration scan    —                 —
    SC.11  —     cleanup   Move NameKind into Domain                          —                 —
    SC.6   —     defect    Symbol-name keys -> NameKind::normalize            SC.11             —
    S3.8d  3b    scaffold  Collapse per-kind lookup to one call               SC.5              —
    S3.8b  3b    scaffold  lookupConstant project reach                       S3.7d,SC.2,SC.6,SC.16,S3.8d  —
    S3.8c  3b    scaffold  Retire the AST-in function lookup from consumers   S3.8a,SC.5        —
    S3.9a  3b    scaffold  Generalize search to a kind parameter              S3.8a,S3.8d       —
    S3.9b  3b    scaffold  Function search + FunctionCandidates migration     S3.9a             —
    S3.10  3b    scaffold  Remove §4.2 fn-path exemption; retire scaffolding  S3.8b,S3.8c,S3.9b —
    S3.11  3     scaffold  Step 3 duplication audit                           all Step 3        —
    S4.1   4     scaffold  TypeClassifier + §4.5/§4.6 static rules            S2.6              —
    S4.2   4     scaffold  Extract node locator + scope analyzer              S3.8c,S4.1        —
    S4.3   4     scaffold  Extract member-access + call-context detectors     S4.2              —
    S4.4   4     scaffold  Extract name-context resolver                      S4.2              —
    S4.5   4     scaffold  Narrow TextFallbackHelper to FQN recovery          S4.3,S4.4         —
    S4.8   4     scaffold  Bring TypeInference inside the boundary            S4.2              —
    S4.6   4     scaffold  SymbolResolver -> glue; CodeResolver positional    S4.2,S4.3,S4.4,S4.5,S4.8  —
    S4.7   4     scaffold  Step 4 duplication audit                           all Step 4        —
    SC.1   —     cleanup   Delete the dead WorkspaceIndexer                   —                 —
    SC.3   —     cleanup   SymbolExtractor reads DeclarationScanner           —                 —
    SC.4   —     cleanup   Dedupe the hand-rolled file:// conversion          —                 —
    SC.7   —     defect    Six member-hierarchy walks -> one                  —                 —
    SC.8   —     cleanup   Prefix matching: SymbolIndex -> PrefixMatcher      —                 —
    SC.12  —     cleanup   Move MemberFilter out of Resolution                —                 —
    SC.13  —     cleanup   Settle Domain->Utility type placement              —                 —
    SC.15  —     cleanup   Oracle corpus: trait adaptations and enums         —                 —
    SC.16  —     defect    Index an open document's global constants          —                 —
    SC.17  —     cleanup   Collapse the hand-routed invalidation fan-out      —                 —
    SC.18  —     cleanup   One home for the kind-qualified symbol key         SC.13             —
    SC.19  —     cleanup   Own the four unowned baseline entries              —                 —
    SC.20  —     cleanup   Own the last unowned layer-contract entry          —                 —
    SZ.1   Z     scaffold  Definition of Done gate + repo-wide dup audit      all prior         —

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
  `OpenDocumentBackend`'s *registration* is collapsed with its lookup, for the same
  reason: a per-kind parameter there would force S3.8b to edit a backend even though the
  read seam held.
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
  `GlobalConstantName` in **S3.8b**, with their lookups.
  - **The `ConstantName` collision is settled (0002 §5.3):** the global FQN type is
    `GlobalConstantName`, `Domain\ConstantName` stays the class member name, and one
    `ConstantInfo` serves both kinds. The *resolved* shape stays open — it is #416's and
    turns on SC.13, so take SC.13 first.
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
  produced them. A removal with no owning slice is how debt stays unowned; put it here.
  Their `Kind` splits them: `defect` where behavior is wrong today, `cleanup` where it is
  redundancy no feature can yet disagree over.
  - **SC.2, SC.5 and SC.6 sit up in the Step 3b rows, not here** — as a reading aid only.
    Each is `defect`, so the `Kind` ranking is what puts it ahead of the Step 3b scaffold
    it must precede; row position carries none of that weight and never should have. Their
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
  - **SC.3** — `SymbolExtractor` is the last hand-written declaration traversal in `src/`:
    it walks the AST itself and hand-tracks `Stmt\Namespace_` to rebuild FQNs that
    `NameResolver` already computed into `namespacedName`. It reads `DeclarationScanner`
    instead, so no consumer can disagree about what a file declares. Its `Class::method`
    symbols are **not** an obstacle to that, as this row long claimed: methods come off
    each class-like's own node via `ClassLike::getMethods()`, which the scanner already
    hands back, so no visitor is needed for them either. Behavior-preserving, so the Step P
    write-path and prefix-search goldens prove it.

    The confinement this satisfies is **already enforced** — `phpstan.neon` restricts
    `NodeTraverser` / `NodeFinder` / `NodeVisitor*` to `ParserService`, the positional
    finders, and `DeclarationScanner`. `SymbolExtractor`'s violations were merely frozen in
    `phpstan-baseline.neon`, which is why nothing failed while they stood. Do not file a
    slice to build that rule; the remaining frozen violations belong to S4.2
    (`SymbolResolver`) and S4.8 (`BasicTypeResolver`).
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
  - **SC.11** — deptrac froze the edges of Knowledge, Index, and Domain classes importing `Resolution\NameKind`; the type is cross-tier currency, so it moves to `Domain` and those baseline entries drain.
    It sits ahead of SC.6 so the case-rule work lands in the type's real home; SC.6 depends on it.
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
  - **SC.12** — `MemberResolver`'s deptrac-frozen edges on `Resolution\MemberFilter`: the same placement defect as SC.11, kept as its own row so each move drains reviewably.
  - **SC.13** — Domain factories reach into Utility (`TypeFactory`, `NamespacePath`); decide the direction in-slice (move the utility into Domain, or the factory methods out) and drain the frozen edges.
    Related: `ClassName::shortName`/`getNamespace` hand-roll the split `NamespacePath` owns, so the direction chosen also settles that duplicate.
    Likewise `NameKind::normalize` re-implements the path fold `NamespacePath::normalize` owns — layer-blocked from routing through it until this move — so the direction also collapses the two folds into one, and the case-folding allowlist follows the file.
  - **SC.15** — `TypeGraphParityTest`'s corpus has no trait `insteadof`/`as` shapes and no enums, so the reflection oracle cannot see #73's defect class (nor enum-interface members).
    Fixture-only slice; #73's fix lands on top of it and must fail before, pass after.
  - **SC.16** — `SymbolExtractor` emits no `SymbolKind::Constant`, so a global constant in an open document is never indexed and `OpenDocumentBackend::childrenOf` cannot enumerate it, while the on-disk and built-in backends both do.
    `WorkspaceNamespaceSource` already maps the kind, so the gap is upstream in the extractor.
    Found by the S3.8d coverage grid on its first run.
    Ungated itself, and a dependency of S3.8b — constant lookup landing on an enumeration blind to open documents would rebuild the §4.2 split on the third symbol namespace.
    That is a real ordering requirement, so it is in `Depends on` rather than implied by where the row sits.
  - **SC.17** — telling the parts that hold file-derived state that a file changed is written out three times: `DocumentSymbolSink` over a list it is handed, `FilesystemBackend` over its catalog and locator, `CompositeSymbolLocator` over its routes.
    The latter two steer by an `instanceof` test, three in all; the sink instead takes a pre-filtered list, so the composition root already decides who holds state and the knowledge is split between the two styles.
    So adding a holder means finding its parent in that tree by hand, and missing one is silent — the stale value is still served and nothing fails.
    Not only caches: the same route drops `AutoloadFilesLocator`'s derived name→file map, which is rebuilt rather than memoized.
    `SymbolSink extends Invalidatable` solely to give the handler a way in, which is how the write path came to be named after the response instead of the event.
    Scope is one registration list at the composition root, which deletes the three fan-outs and the three type tests. Whether a general published event replaces it is #415 and is deliberately not settled here.
    Found while reviewing S3.8d. Ungated.
  - **SC.18** — the key a name has under its kind, `$kind->name . '|' . $kind->normalize($name)`, is written out four times: `SymbolCache::keyFor`, `OpenDocumentBackend::key`, `DeclarationSymbolInfoFactory::collect`, and the composite's test fake.
    `SymbolCache::keyFor` and `delete` are public for one caller — `FilesystemBackend` holds hashed key strings to reverse-map a path — so a `forget(QualifiedName, NameKind)` takes both off the surface and lets the backend record what it actually knows.
    Duplication rather than a defect: the four stores are independent, so no two features can disagree over it today. It is filed because a fifth copy arrives with each new kind.
    Gated on SC.13, which decides where the case fold lives; the key helper belongs beside it.
    Found while reviewing S3.8d.
  - **SC.7** — `MemberResolver` has six near-identical hierarchy walks:
    `find{Method,Property,Constant}InHierarchy` and `collect{Methods,Properties,Constants}`,
    each a seen-check, a scan of the class's own members, and a recursion over
    `supertypes()`. #334 centralised the type-graph *edges*, and `supertypes()` is correct
    and stays; the *walk* over them was never centralised, so a new member kind adds two
    more copies. Outside Step 4's scope — that step decomposes `src/Resolution/`, this is
    `src/Repository/`.
    The member-name case rule rides along: the collect keys (`strtolower`), `MethodName::equals` (`strcasecmp`), and the fallback's raw merge key disagree today, and the walks' seen-sets key raw FQNs where `ClassName::equals` is case-insensitive.
  - **SC.19** — four `phpstan-baseline.neon` entries that no row above drains:
    `ReferenceResolver`'s `strcasecmp`, and the `preg_match` calls in `CompletionHandler`
    and `NamedArgumentCandidates`. The case fold belongs to `NameKind`; the two regexes
    belong in `CompletionClassifier`, which is where the allowlist already puts
    text-pattern analysis. Filed because the baseline must reach zero and an entry with no
    owning slice is how it stalls — found by auditing the baseline against this table.
    It covers `phpstan-baseline.neon` only; the other baseline is SC.20.
  - **SC.20** — `deptrac.baseline.yaml` freezes `DefaultFunctionRepository` depending on
    `Index\DeclarationScanner`, and no row removes it. `Repository` may not reach `Index`
    under the layer contract, and SC.5 created the edge on purpose, so it is real rather
    than an oversight: the repository has to ask what a file declares, and the scanner is
    the one thing that answers.
    S3.10 is not the remover, though it looks like it — S3.10 retires that class's AST-in
    *signature*, which is a different thing from the dependency and leaves it standing.
    The slice decides which way the edge goes: let `Repository` read `Index`, move the
    scanner somewhere both may reach, or route the repository through a seam it already
    has. Take it after S3.10, whose teardown may narrow the choice.
    The only entry either baseline still holds with no owning row. Found while reviewing
    the wording of SC.19, which had claimed all four of its own were the last.
  - **SC.8** — `Completion\PrefixMatcher::matches` and `SymbolIndex::findByPrefix` both
    hand-roll `str_starts_with(strtolower(...))`. SC.6 owns the `strtolower` half (it is
    the same per-kind case rule); what is left here is the duplicated *matching* helper, so
    take SC.6 first or the two rows fight over the same line.
- **S4.8** — see 0002 Step 4: the variable-binding walks and expression dispatch are
  duplicated across `src/Resolution/` and `src/TypeInference/`, and the latter sat outside
  the original decomposition's directory boundary. Its guardrail-baseline entries are part
  of the Step 4 drain; gated on S4.2 because both edit `SymbolResolver`.
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
- **Feature-matrix runner — deprioritized (2026-08-10).** A fixture-scenario × handler-registry grid (every cell asserts that handler's observable or registers not-applicable; an unregistered cell fails), intended as the cross-feature agreement net and the TDD vehicle for new language features. Revisit after the Step 4 drain and the Wave 3 axes.
- **Wave 3 — member kind, access context, intent detection.** The axes the 2026-08-10 RFC amendment added (RFC 1 §3.1, Appendix A "target" rows). Sliced when reached, after Step 4; property hooks and asymmetric visibility are the features waiting on the first two.
