# Build Manifest (ordered checklist)

    Status:   Draft — Wave 3 open
    Driver:   build-procedure.md
    Plan:     0002-execution-plan.md

The ordered list of build slices, derived from the plan.
Goals, requirements and acceptance criteria live in 0002 and RFC 1; this file adds only the order.

Work top to bottom: a slice may start once every slice above it is merged.
Progress is not recorded here — a slice's status is whether a merged PR exists for `slice/<ID>` (see `build-procedure.md`).
**Step** names the plan step in 0002 whose acceptance criteria the slice must meet.

Append later phases as they are reached; do not create the whole tree up front.

## Wave 1 — Steps 0, 1, P, 2

    ID     Step  Title
    -----  ----  ---------------------------------------------------
    S0.1   0     Instrument parse count/time; run the spike
    S0.2   0     Request-scoped parse dedup
    S1.1   1     Read ClientCapabilities -> SessionCapabilities
    S1.2   1     Negotiate positionEncoding; convert at the edge
    S1.3   1     Shape hover markup / snippets via capabilities
    S1.4   1     Lifecycle state + malformed-frame robustness
    S1.5   1     Position round-trip corpus
    SP.1   P     Per-surface parity harness + coverage gate
    S2.1   2     Define SymbolSource/SymbolSink + delegating facade
    S2.2   2     Migrate ClassCandidates -> search
    S2.3   2     Migrate NamespaceCandidates -> childrenOf
    S2.4   2     Migrate SymbolResolver lookups -> lookupClassLike
    S2.5   2     Migrate TextDocumentSyncHandler -> SymbolSink
    S2.6   2     §4.2 enforcement rule (scoped-exempt FunctionRepo)

## Wave 2 — Steps 3a, 3b

    ID     Step  Title
    -----  ----  ---------------------------------------------------
    S3.1   3a    Existing caches -> replaceable §5.3 seam
    S3.2   3a    Dedupe the duplicate ComposerAutoloadMap
    S3.3   3a    Named backends + fixed-precedence composite
    S3.4   3a    One parse / one write path + consistency check
    S3.5   3a    External-file-change invalidation
    S3.6   3b    Function-surface golden + Builtin enum oracle
    SC.4   3     Dedupe the hand-rolled file:// conversion
    S3.7a  3b    Read autoload.files into ComposerAutoloadMap
    S3.7b  3b    Scan a file for the declarations it makes
    S3.7c  3b    ClassLocator -> kind-agnostic SymbolLocator
    S3.7d  3b    Derived autoload.files index, for all three kinds
    S3.7e  3b    Enumerate the derived index in childrenOf
    S3.8a  3b    lookupFunction project reach

## Wave 3 — Steps 3, 4, Z

    ID  Step  Title
    --  ----  ---------------------------------------------------
    1   3     Delete the dead WorkspaceIndexer
    2   3     §4.1 handler-responsibility architecture test
    3   3     §4.3 read/write segregation rule
    4   3     §4.3 single-write-path architecture test
    5   3     §5.3 backend precedence + cache-seam test
    6   3b    One walk reports a file's declarations with nodes
    7   3b    FilesystemBackend consumes the one walk
    8   3b    DocumentSymbolSink consumes the one walk
    9   3b    Register class-likes at any depth on the write path
    10  3b    SymbolExtractor consumes the one walk
    11  3b    One backend lookup: resolve(name, kind)
    12  3b    Kind-agnostic locate at the facade
    13  3     §4.11 rule: one owner per shared mechanism
    14  3b    Retire the AST-in function lookup from consumers
    15  3b    Generalize search to a kind parameter
    16  3b    Answer function search in every backend
    17  3b    Migrate FunctionCandidates; retire getFileFunctions
    18  3b    Settle the two ConstantName types
    19  3b    lookupConstant project reach
    20  3b    Constant search and enumeration
    21  3b    Report bounded search coverage
    22  3b    Remove the §4.2 function-path exemption
    23  3     Step 3 duplication audit
    24  4     TypeClassifier owns the predicates
    25  4     §4.5 rule: no kind branching, no Type instanceof
    26  4     §4.6 rule: no `new` of a Type outside the factory
    27  4     Extract the node locator
    28  4     Extract the scope analyzer
    29  4     Extract the member-access detector
    30  4     Extract the call-context detector
    31  4     Extract the name-context resolver
    32  4     Retire ScopeFinder's superseded import extraction
    33  4     Narrow TextFallbackHelper to FQN recovery
    34  4     SymbolResolver -> glue; CodeResolver positional
    35  4     Step 4 duplication audit
    36  Z     Definition of Done gate

## Deferred (not scheduled; excluded from selection until reached)

A row in the table is pickable, so these are kept out of it until their phase is reached.
Each is appended as ordered slices at that point.

- **Step 5 — environment-parameterized built-ins (§4.7).** Blocked on an open decision: its version-aware source is TBD (0002 §7). Tracked as #401.
- **Step 6 — scheduler / async tier.** Deferred until a push feature needs it (#266).
- **Workspace scope.** Project-wide search, references, implementations, and hierarchies need an index this plan does not build (#264, #265).
