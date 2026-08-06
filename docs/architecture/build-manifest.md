# Build Manifest (slice registry)

    Status:   Draft — seeded through Wave 2 (Steps 3, 4, Z; Steps 5, 6 deferred)
    Driver:   build-procedure.md
    Plan:     0002-execution-plan.md

This is a **static** registry of build slices. It records *what* the slices are and
how they depend on each other; it does **not** record progress — a slice's status is
computed from whether its PR (`slice/<id>`) is merged (see `build-procedure.md`).

Append later phases as they are reached; do not create the whole tree up front.

## Columns

- **ID** — stable slice id; the branch is `slice/<ID>`. Merged slices keep the
  step-encoded ids they were built under; every new row is a plain number, assigned in
  sequence and never reused. An id is only a branch name — order is table position and
  sequencing is the `Depends on` column, so encoding either into the id just makes it
  wrong later.
- **Row order.** New rows go below the last `done` row, never above it — a completed
  row's position is history. Within that pending block they may be placed anywhere, and
  unblocked removals go at its top: `/do-next` takes the first unblocked `todo` in table
  order, so position is the only thing that gets dead and duplicated code removed before
  the work that has to read past it.
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
Definition-of-Done gate. Steps 3 and 4 each end with a duplication audit (slices 12 and
20), which Step Z re-runs repo-wide as its completion gate.

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
    SC.4   —     Dedupe the hand-rolled file:// conversion          —                 —
    1      —     Delete the dead WorkspaceIndexer                   —                 —
    2      —     SymbolExtractor -> the parser's namespacedName     —                 —
    3      —     §4.1 handler-responsibility architecture test      —                 —
    4      —     §4.3 read/write segregation rule                   —                 —
    5      3b    One declaration walk; backends consume it          S3.8a             —
    6      3b    §8.1 rule: one owner per shared mechanism          5                 —
    7      3b    Retire the AST-in function lookup from consumers   5                 —
    8      3b    Generalize search to a kind parameter              S3.8a             —
    9      3b    Function search + FunctionCandidates migration     6,8               —
    10     3b    Constant vertical: lookup, search, enumeration     6,8               —
    11     3b    Remove §4.2 fn-path exemption; retire scaffolding  7,9,10            —
    12     3     Step 3 duplication audit                           all Step 3        —
    13     4     TypeClassifier + §4.5/§4.6 static rules            S2.6              —
    14     4     Extract node locator + scope analyzer              7,13              —
    15     4     Extract member-access + call-context detectors     14                —
    16     4     Extract name-context resolver                      14                —
    17     4     Narrow TextFallbackHelper to FQN recovery          15,16             —
    18     —     Retire ScopeFinder's superseded import extraction  16,17             —
    19     4     SymbolResolver -> glue; CodeResolver positional    14,15,16,17       —
    20     4     Step 4 duplication audit                           all Step 4        —
    21     Z     Definition of Done gate + repo-wide dup audit      all prior         —

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
- **The remaining kinds are cut by symbol namespace, not by layer.** A layer cut
  (interface, then backends, then consumers) would land `SymbolBackend` methods no
  backend implements, so each slice is instead one vertical: a kind's name type, its
  `SymbolSource`/`SymbolBackend` method across all four backends, and its tests. Slice 7
  then migrates the consumers (`SymbolResolver`, `BasicTypeResolver`) off
  `FunctionRepository::get(string, array $ast)` — it is the Step 3b slice that edits
  `SymbolResolver`, so it, not S3.8a, is what slice 14 serializes against (§6).
  Search divides on provability rather than kind: **slice 8** widens `searchClassLikes`
  to `search(string $prefix, NameKind $kind)` with class-likes still the only searchable
  kind, which is behavior-preserving and leaves every Step P golden frozen; **slice 9**
  makes the backends answer function search and moves `FunctionCandidates` onto it,
  rewriting only the function-surface golden S3.6 froze. Note `BuiltinBackend` MUST
  answer function search in slice 9 or built-in function completion regresses — that
  golden is what catches it.
- **A kind vertical consumes shared mechanism; it MUST NOT add one** (RFC §4.11). That
  cut is *backends × kinds*, which 0002 §5.6 requires to stay closed, and S3.8a broke
  it: it added `parseFunctionFrom` beside `FilesystemBackend::findClassInAst` instead of
  using `DeclarationScanner`, the whole-file walk S3.7b had built one slice earlier.
  - **Slice 5** gives the remaining verticals something to consume: one walk yielding
    each declaration *with its node*, since needing the node for `ClassInfoFactory` /
    `FunctionInfo::fromNode` is a return-shape difference, not a second query.
    Every walk answering "what does this file declare" is deleted:
    `FilesystemBackend`'s `parseFunctionFrom` and `findClassInAst`, and
    `DocumentSymbolSink`'s `functionsIn` and `classesIn`. The write path is not
    optional here — it is the open-document backend's, so leaving it out would land a
    slice titled "backends consume it" where one backend does not. It is also where the
    split already shows: `functionsIn` reports a declaration at any depth while
    `classesIn` reads top-level statements only, which is the §4.2 coverage split
    inside one class. Behavior-preserving for lookup, so the class-like-lookup and S3.6
    function-surface goldens stay frozen; the class-like depth change is new behavior on
    the write path, proven by a fixture and a rewritten Step P write-path golden.
  - **Slice 6** adds the rule, per 0002 §Duplication audits ("an audit's first *fix* is
    to add the rule"): one named owner per shared mechanism, the owners named in the
    rule's own configuration (RFC §8.1). Every AST traversal in `src/` must be
    classified by it, so the disposition of all of them is fixed here rather than
    discovered mid-slice:
    - **Owners.** `DeclarationScanner` (declaration walk, after slice 5);
      `NodeAtPosition`, `Scope`, `ScopeFinder` (positional walks — a different
      mechanism, not exemptions); `ParserService` (the `NameResolver` pass every parse
      runs through); `BasicTypeResolver` (assignment-flow walk, its own mechanism).
    - **Declared migrations**, each already carrying a remover: `SymbolExtractor`
      (slice 2), `ScopeFinder`'s import extraction (slice 18), `DefaultFunctionRepository`
      (slice 11, which deletes the AST-in signature), `SymbolResolver`'s own walks
      (slice 19, which reduces it to glue).
    - No allowlist entry may be added without a remover slice, since slice 21 requires
      zero remaining scope.
  - **Slice 10** lands constants whole rather than lookup-first: splitting a kind across
    a lookup slice and a later search slice is what produced S3.7e, and RFC §4.2 requires
    identical coverage across the two. Enumeration is likely already satisfied by S3.7e's
    per-declaration `NameKind`, making it a fixture to prove rather than code to write;
    offering constants at expression start (#317) is a feature on this reach and stays
    out of scope.
- **Name-type model is JIT (§5.3).** Each type lands with its first caller, not ahead
  of it. `NameKind` already exists (it predates Wave 2, as the catalog's coarse kind);
  Step 2 carries `ClassLikeName` / `NamespaceName`; `QualifiedName` lands in **S3.7b**,
  whose `DeclarationScanner` is its first caller; `FunctionName` in **S3.8a** and
  `ConstantName` in **slice 10**, with their lookups.
  - **`ConstantName` is already taken.** `Domain\ConstantName` wraps a *class* constant
    name; §5.3's `ConstantName` is a *global* constant FQN. Decide the naming before
    slice 10 rather than inside it — this is the same coexistence question §7 leaves open
    for `ClassLikeName` versus `ClassName`.
- **Steps 3 and 4 both edit `SymbolResolver` (§6).** Slice 14 (positional extraction) is
  gated on slice 7 (the 3b lookup migration) so the two never run concurrently; manifest
  order keeps Step 3 ahead of Step 4 regardless. Slice 13 (`TypeClassifier` + the
  §4.5/§4.6 rules) is independent of Step 3 and may proceed alongside.
- **Teardown discharge.** S3.2 removes the duplicate `ComposerAutoloadMap`; S3.4 the
  Step 2 double-write facade and (if built) the Step 0 cache rider; slice 5 the bespoke
  declaration walks; slice 11 the §4.2 function-path exemption, `getFileFunctions`, and
  the `DefaultFunctionRepository` AST-in signature; slices 17 and 19 the `SymbolResolver`
  god class, `TextFallbackHelper` breadth, and the `CodeResolver` knowledge-facing
  methods. Slice 21 verifies the ledger is fully discharged.
- **Stepless rows carry no step, on purpose.** They are duplication, dead code, and
  missing enforcement that no step's acceptance covers — which is exactly how they went
  unowned until an audit found them. They are in the table so `/do-next` can select them
  and slice 21 can require them. A removal with no owning slice is a defect; put it here,
  at the top of the pending block (see Row order).
  - **Slice 1** — `Index/WorkspaceIndexer` has zero references in `src/` or `tests/`.
  - **Slice 2** — `SymbolExtractor` hand-tracks `Stmt\Namespace_` to build FQNs that
    `NameResolver` already computed into `namespacedName` (which `DefaultClassInfoFactory`,
    `DefaultFunctionRepository`, `ScopeFinder`, and `DeclarationScanner` all read).
    Behavior-preserving, so the Step P write-path golden proves it. `SymbolExtractor`'s
    `Class::method` FQNs are its own and stay. The other site this once covered,
    `FilesystemBackend::findClassInAst`, is removed by slice 5 independently.
  - **Slices 3 and 4** — §8.1 names an enforcement mechanism per invariant and slice 21
    requires every one active repo-wide, so a mechanism with no owning slice is a gate
    that cannot pass. `phpstan.neon` registers two rules today (§4.2, §4.8); §4.1
    (handlers depend on no parser, repository, or reflection) and §4.3 (consumers depend
    on `SymbolSource` / `SymbolSink`, never a concrete implementation) have none. §4.1 is
    the axis closed by #190/#253/#256 and held by documentation ever since, which §8.1
    forbids; §4.3's seam is Step 2's, whose acceptance named only the §4.2 rule, so it is
    a late Step 2 obligation rather than pre-existing debt (0002 §Teardown ledger).
    Each is a rule plus its `RuleTestCase`, not a migration: §4.1 is green in `src/`, and
    §4.3 is green once the rule scopes "consumer" to code outside the knowledge and index
    tiers — `Server.php` and `KnowledgeStack` are composition roots, and
    `WorkspaceNamespaceSource`, `OpenDocumentBackend` and `DocumentIndexer` are inside
    the tier that owns the concrete stores. Fix that scope in the slice; a wider reading
    turns slice 4 into a migration it is not planned as.
  - **Slice 18** — `ScopeFinder::extractImports` / `resolveFromUseStatements` were
    superseded by `NameContextFactory` and their own docblock says they go away once #331
    moves the callers. #331 landed (#337); three callers did not move (`SymbolResolver`
    ×2, `TextFallbackHelper` ×1). Gated on slices 16 and 17, which rewrite those sites.
  - **SC.4** — `file://` URI and path conversion was hand-rolled in four live places, each
    differing in how it handled the scheme and percent-encoding. One `FileUri` replaced
    them. Found while splitting S3.7, whose locator wanted a fifth copy.
- **Each section ends with a duplication audit** (**slices 12 and 20**), and Step Z's
  acceptance carries the repo-wide one. Method, scope and outcome rule are defined once
  in 0002 §Duplication audits and the terminal condition is a Step Z acceptance item —
  not restated here, because a manifest note is not a gate.
  - Slices 12 and 20 are **tracking** gates: a finding may be handed to a new slice
    rather than fixed in place, so a removal belonging to a later section is not dragged
    forward. Slice 21 is the **completion** gate, where an unowned or unfixed duplicate
    fails outright.
  - The three depend on their whole section (`all Step N` / `all prior`) rather than on
    its last slice. A chain of ids is both stale-prone and wrong: slice 11's transitive
    dependencies never reach S3.4 or S3.5, so a Step 3 audit gated on slice 11 could have
    run with two of its slices unbuilt.
- **The Builtin backend stood up in S3.3 is reflection-backed and does not satisfy
  §4.7** (0002 §5 known gap), tracked as #401; its fix is the deferred Step 5.
- **`Closes` is assigned at slice-issue creation, after a reviewer reads the issue —
  never inferred.** Candidates from Wave 1's note: #239 / #181 / #317 land somewhere in
  S3.7a–slice 11; #295 (Visibility enum) wants a small cleanup slice not yet placed.
  - **#181 covers all three kinds**, which is now what S3.7b–S3.7d build: the
    `files` set has no name→file map of any kind, so it is scanned whole. A slice
    claiming #181 must show class-like reach, not just functions and constants.
    - **#181 is not closable before slice 9.** Its acceptance asks for these symbols in
      *hover and completion*, not merely resolvable: functions need S3.8a and slice 9,
      constants slice 10, namespace completion S3.7e. It also asks for a startup-time
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
  interim reflection Builtin backend already ships in S3.3; §4.7 is tracked as **#401**.
  Nothing depends on Step 5, and slice 21 permits it to remain a named gap.
- **Position encodings other than UTF-16 (#192, #371).** The boundary conversion and the
  interior byte columns are done (S1.2, S1.5), but the negotiated encoding does not reach
  `TextDocument`, so only the UTF-16 default is served. A client-compatibility feature
  rather than foundation work: [LSP] requires UTF-16 support and the encoding is
  negotiated, so a client offering nothing else is served correctly today. Slice 21
  permits it as a named gap.
- **Step 6 — scheduler / async tier (#266).** Deferred until a push feature needs it.
  Sketch, from its acceptance: `$/cancelRequest` cancellation of superseded work;
  debounced `publishDiagnostics` on change; a background scheduler that does not starve
  interactive requests and is cancelable, feature-detecting `pcntl` / `ext-parallel`
  with a synchronous fallback. Appended as slices when a push feature (or #266) takes
  it up.
