# Build Manifest (ordered checklist)

    Status:   In-Flight
    Driver:   build-procedure.md
    Plan:     0002-execution-plan.md

The ordered list of build slices, derived from the plan.
Goals, requirements and acceptance criteria live in 0002 and RFC 1; this file adds only the order.

Work top to bottom.
Order is the sequencing: a row may be started once every row above it is merged.
A slice's status is not recorded here — it is whether a merged PR exists for `slice/<ID>` (see `build-procedure.md`).

**Step** names the plan step whose acceptance criteria the slice must meet.
A slice with no step removes pre-existing duplication or dead code that no step's acceptance covers.

Append later phases as they are reached; do not create the whole tree up front.

## Wave 1 — Steps 0, 1, P, 2

    ID     Step  Title
    -----  ----  -------------------------------------------------
    S0.1   0     Instrument parse count/time; run the spike
    S0.2   0     Request-scoped parse dedup (if spike warrants)
    S1.1   1     Read ClientCapabilities -> SessionCapabilities
    S1.2   1     Negotiate positionEncoding; convert at the edge
    S1.3   1     Shape hover markup / snippets via capabilities
    S1.4   1     Lifecycle state + malformed-frame robustness
    S1.5   1     Position round-trip corpus (regression net)
    SP.1   P     Per-surface parity harness + branch-coverage gate
    S2.1   2     Define SymbolSource/SymbolSink + delegating facade
    S2.2   2     Migrate ClassCandidates -> search
    S2.3   2     Migrate NamespaceCandidates -> childrenOf
    S2.4   2     Migrate SymbolResolver class lookups -> lookupClassLike
    S2.5   2     Migrate TextDocumentSyncHandler -> SymbolSink
    S2.6   2     §4.2 enforcement rule (scoped-exempt FunctionRepo)

## Wave 2 — Steps 3, C

    ID     Step  Title
    -----  ----  -------------------------------------------------
    S3.1   3a    Existing caches -> replaceable §5.3 seam (verify)
    S3.2   3a    Dedupe the duplicate ComposerAutoloadMap
    S3.3   3a    Named backends + fixed-precedence composite
    S3.4   3a    One parse / one write path + consistency check
    S3.5   3a    External-file-change invalidation
    S3.6   3b    Function-surface golden + Builtin enum oracle
    S3.7a  3b    Read autoload.files into ComposerAutoloadMap
    S3.7b  3b    Scan a file for the declarations it makes
    S3.7c  3b    ClassLocator -> kind-agnostic SymbolLocator
    S3.7d  3b    Derived autoload.files index, for all three kinds
    S3.7e  3b    Enumerate the derived index in childrenOf
    SC.4   —     Dedupe the hand-rolled file:// conversion
    S3.8a  3b    lookupFunction project reach

## Wave 3

    ID     Step  Title
    -----  ----  -------------------------------------------------
    1      —     Delete the dead WorkspaceIndexer
    2      —     SymbolExtractor -> the parser's namespacedName
    3      —     §4.1 handler-responsibility architecture test
    4      —     §4.3 read/write segregation rule
    5      3b    One declaration walk; backends consume it
    6      3b    One backend lookup: resolve(name, kind)
    7      3b    §8.1 rule: one owner per shared mechanism
    8      3b    Retire the AST-in function lookup from consumers
    9      3b    Generalize search to a kind parameter
    10     3b    Function search + FunctionCandidates migration
    11     3b    Constant reach: lookup, search, enumeration
    12     3b    Remove §4.2 fn-path exemption; retire scaffolding
    13     3     Step 3 duplication audit
    14     4     TypeClassifier + §4.5/§4.6 static rules
    15     4     Extract node locator + scope analyzer
    16     4     Extract member-access + call-context detectors
    17     4     Extract name-context resolver
    18     4     Narrow TextFallbackHelper to FQN recovery
    19     —     Retire ScopeFinder's superseded import extraction
    20     4     SymbolResolver -> glue; CodeResolver positional
    21     4     Step 4 duplication audit
    22     Z     Definition of Done gate + repo-wide dup audit

Steps 5 (environment-parameterized built-ins) and 6 (scheduler / async tier) are deferred and carry no slices; see 0002.
Position encodings other than UTF-16 (#192, #371) are likewise deferred: UTF-16 is what LSP requires and the encoding is negotiated, so a client offering nothing else is served correctly.
