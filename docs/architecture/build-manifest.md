# Build Manifest (slice registry)

    Status:   Wave 1 complete; Wave 2 in progress (Steps 5, 6 deferred)
    Driver:   build-procedure.md
    Plan:     0002-execution-plan.md

## The goal every row serves

**Eliminate every M×N pair in the codebase, and make recurrence impossible by rule.**

An M×N pair is M consumers each hand-writing their own handling of N cases — handler × node type, backend × symbol kind, completion position × symbol kind, member kind × hierarchy walk.
They drift, and when they drift two features disagree about the same code: #190, #253 and #256 are all one bug wearing three faces.
The full axis list is Appendix A of RFC 1; three axes are deferred (member kind, access context, target environment).

Nothing else here outranks that.
A slice that removes a pair is worth more than a slice that adds reach, and a slice that adds reach while leaving a pair open is a regression however useful the reach.
Not-yet-started functionality is in scope too: a feature that cannot be built without a new per-case consumer is a feature whose axis has no extension point yet, and the extension point is the work (RFC 1 §7).

Elimination is not enough on its own, because the next contributor cannot see a pair that is merely absent.
Every axis therefore ends with a **rule** that fails analysis when a consumer branches per-case, so the pair cannot come back.
That is why **enforcement-rules** comes first: until those rules exist, no slice — landed or pending — can prove it moved toward the goal.

## How to read this

- Remaining work is an **ordered list**. Each row starts when the one above it merges.
- The **slug** (bold text) is the slice id; the branch is `slice/<slug>`.
- Status is **computed from whether `slice/<slug>` is merged**, never from a field here.
- **Landing a slice includes checking its box.** The PR that merges the slice also checks the box — one diff, one review. Rows move to *Complete* in bulk when a wave closes; explanatory notes go into git history, not the Complete section.
- To reorder: move the line. No cross-references to update.

## Remaining work

Ordered. Each row starts when the one above it merges.

- [ ] **enforcement-rules** — Install every M×N enforcement rule

  One slice, every unbuilt mechanism RFC 1 §8.1 designates. They were specified together and lapsed together; splitting them just recreates the window in which some axes are guarded and others are not.

  - **§4.5** — no `instanceof` against a concrete `Type` / `ResolvedSymbol` implementation, and no `match`/`switch` on a symbol-kind enum, outside the metadata factories and the classifier. This is the rule the whole goal turns on.
  - **§4.6** — no `new` of a `Type` implementation outside `TypeFactory`.
  - **§4.3 / §5.2** — the single-write-path check as an architecture test. S3.4 landed it as `DocumentSymbolSink::assertStoresAgree`, a runtime `assert()`, which is disabled in production; the mechanism is off in the only environment that matters.
  - **§4.11** — the AST/text agreement tests: the AST path and the text-fallback path must answer the same question the same way on parseable fixtures. Writable against the code as it stands, and landing it first constrains the Step 4 refactor instead of trailing it.
  - **§5.4** — a safe default for every capability the client did not declare. §5.4 has no §8.1 row at all; §4.8's rule enforces *where* raw parameters may be read, not this. Add a capability with no default and nothing fails today. Same shape as the §5.1 coverage grid.

  **The baselines grow, once.** That is expected and correct: a rule reveals pre-existing violations, it does not introduce them (RFC 1 §8.1). `bin/check-baseline-shrink` will fail on the growth — say so in the PR body and merge anyway; that gate exists to stop unconscious growth, and this is the one conscious event. From that commit the totals shrink again and must reach zero.

  Note what the growth means: every violation these rules report has been in the tree all along, unrecorded. "The baselines are shrinking" has only ever measured the rules that were built.

- [ ] **search-kind-param** — Generalize search to a kind parameter

  `searchClassLikes($prefix)` becomes `search($prefix, NameKind $kind)`, with class-likes still the only searchable kind. Behavior-preserving, so every Step P golden stays frozen. This is what **completion-kind-collapse** needs: expression-start has no namespace path, so enumeration alone cannot serve `PHP_E|`.

- [ ] **completion-kind-collapse** — Collapse completion's per-kind sources and kind branches

  The largest §4.5 violation, and the one that proves the rule works. Two forms, both in `src/Completion/`:

  `NamespaceCandidates::offerSymbol` decides suitability by branching on a kind enum — it drops every symbol `childrenOf` hands it that is not a class-like. `getExpressionCompletions` fans out to one source per kind at a position that is genuinely kind-ambiguous, which §4.5 names outright ("an ad hoc scan that branches on each candidate kind's result").

  Together they are why S3.8b made a global constant hoverable and jump-to-able while completion could not offer one — the §4.2 lookup/enumeration split, one tier above the seam where §4.2 is enforced.

  The collapse is what makes a constant appear *without* a `ConstantCandidates` existing: position filters take the typed name the symbol denotes and default to accepting it, restricting positions ask a `CodeResolver` predicate (`isInstantiable`, `isInterface`), and a single kind-dispatched factory is the one place a kind is named. Adding a kind then breaks exactly one `match` and every position keeps working.

  Consequently **#317 must be rewritten, not built as filed.** It specifies a new per-kind source mirroring `FunctionCandidates` — a consumer edit that RFC 1 §7 forbids for a new symbol kind — including a direct `get_defined_constants()` that S3.8b confined to `InternalConstantSet`. Its Part 2 (namespace-correct references via `ReferenceResolver`) is unaffected and still wanted.

- [ ] **function-search** — Function search + FunctionCandidates migration

  Backends answer function search; `FunctionCandidates` moves onto the seam and its frozen `get_defined_functions()` baseline entry drains. `BuiltinBackend` **must** answer function search here or built-in function completion regresses — the function-surface golden S3.6 froze is what catches it.

- [ ] **retire-ast-in-lookup** — Retire the AST-in function lookup from consumers

  Moves `SymbolResolver` and `BasicTypeResolver` off `FunctionRepository::get(string, array $ast)`. This is the slice that edits `SymbolResolver` before the Step 4 decomposition, so **node-locator** serializes against it.

- [ ] **retire-function-exemption** — Remove §4.2 function-path exemption; retire scaffolding

  Drops S2.6's scoped exemption for `FunctionRepository`, `getFileFunctions`, and the `DefaultFunctionRepository` AST-in signature. Its reflection entries in `phpstan-baseline.neon` drain with it.

- [ ] **drain-enforcement-entries** — Drain the enforcement-rules entries no Step 4 row owns

  The §4.6 `new`-of-a-Type sites outside `TypeFactory` (`DefaultClassInfoFactory`, and whatever else the rule finds), plus any §5.4 default the capability grid reports missing. `SymbolResolver`, `TextFallbackHelper` and `BasicTypeResolver` are excluded — later rows own those files.

- [ ] **type-classifier** — TypeClassifier

  *(Its §4.5/§4.6 rules moved to enforcement-rules.)*

- [ ] **node-locator** — Extract node locator + scope analyzer

- [ ] **member-call-detectors** — Extract member-access + call-context detectors

- [ ] **name-context** — Extract name-context resolver

- [ ] **text-fallback-narrow** — Narrow TextFallbackHelper to FQN recovery

- [ ] **type-inference-merge** — Bring TypeInference inside the boundary

  The variable-binding walks and expression dispatch are duplicated across `src/Resolution/` and `src/TypeInference/`; the latter sat outside the original decomposition's directory boundary.

- [ ] **resolver-glue** — SymbolResolver → glue; CodeResolver positional-only

  `CodeResolver` reduces to the positional-facing interface; its knowledge-facing responsibilities are served by `SymbolSource`, with no second knowledge interface.

- [ ] **done** — Definition of Done + repo-wide duplication audit

  Both baselines empty, every teardown discharged, and no unowned duplicate anywhere in `src/`.

  This absorbs the former per-section audits (S3.11, S4.7). They were hand-run tracking gates from before the rules existed; **enforcement-rules**'s rules are the continuous version, and running a manual audit twice more in the middle adds ceremony without adding a check. One completion gate, where an unowned or unfixed duplicate fails outright.

## Complete

Wave 1 — Steps 0, 1, P, 2.

    ID     Title
    -----  -------------------------------------------------
    S0.1   Instrument parse count/time; run the spike
    S0.2   Request-scoped parse dedup
    S1.1   Read ClientCapabilities -> SessionCapabilities
    S1.2   Negotiate positionEncoding; convert at the edge
    S1.3   Shape hover markup / snippets via capabilities
    S1.4   Lifecycle state + malformed-frame robustness
    S1.5   Position round-trip corpus
    SP.1   Per-surface parity harness + branch-coverage gate
    S2.1   Define SymbolSource/SymbolSink + delegating facade
    S2.2   Migrate ClassCandidates -> search
    S2.3   Migrate NamespaceCandidates -> childrenOf
    S2.4   Migrate SymbolResolver lookups -> lookupClassLike
    S2.5   Migrate TextDocumentSyncHandler -> SymbolSink
    S2.6   §4.2 enforcement rule (scoped-exempt FunctionRepo)

Wave 2 — Step 3, and the SC.* debt that predated the plan.

    ID     Title
    -----  -------------------------------------------------
    S3.1   Existing caches -> replaceable §5.3 seam
    S3.2   Dedupe the duplicate ComposerAutoloadMap
    S3.3   Named backends + fixed-precedence composite
    S3.4   One parse / one write path + consistency check
    S3.5   External-file-change invalidation
    S3.6   Function-surface golden + Builtin enum oracle
    S3.7a  Read autoload.files into ComposerAutoloadMap
    S3.7b  Scan a file for the declarations it makes
    S3.7c  ClassLocator -> kind-agnostic SymbolLocator
    S3.7d  Derived autoload.files index, all three kinds
    S3.7e  Enumerate the derived index in childrenOf
    S3.8a  lookupFunction project reach
    S3.8d  Collapse per-kind lookup to one call
    S3.8b  lookupConstant project reach
    SC.1   Delete the dead WorkspaceIndexer
    SC.2   Retire ScopeFinder's superseded import extraction
    SC.3   SymbolExtractor reads DeclarationScanner
    SC.4   Dedupe the hand-rolled file:// conversion
    SC.5   One declaration finder, not five hand-written
    SC.6   Symbol-name keys -> NameKind::normalize
    SC.7   Six member-hierarchy walks -> one
    SC.8   Prefix matching: SymbolIndex -> PrefixMatcher
    SC.9   Class-like registration -> one declaration scan
    SC.11  Move NameKind into Domain
    SC.12  Move MemberFilter out of Resolution
    SC.13  Settle Domain->Utility type placement
    SC.15  Oracle corpus: trait adaptations and enums
    SC.16  Index an open document's global constants
    SC.17  Collapse the hand-routed invalidation fan-out
    SC.18  One home for the kind-qualified symbol key
    SC.19  Own the four unowned baseline entries
    SC.20  Own the last unowned layer-contract entry

SC.10 and SC.14 were never built: SC.14 was struck from the manifest (#430) and SC.10 was never filed.

Verified beyond the merge: `deptrac.baseline.yaml` is empty, and SC.19's four `phpstan-baseline.neon` entries are gone. What the PHPStan baseline still holds belongs to **function-search**, **retire-function-exemption**, **node-locator**, **text-fallback-narrow** and **type-inference-merge**, each named on its row above.

One acceptance carve-out is recorded rather than reopened: **S3.4** landed its write-path consistency check as a runtime `assert()`, which is not the architecture test §8.1 designates. **enforcement-rules** carries the promotion.

## Deferred (excluded until reached)

- **Step 5 — environment-parameterized built-ins (§4.7).** Not plannable: its version-aware source is an open TBD (0002 §7, explicitly not `phpstorm-stubs`). The reflection-backed Builtin backend from S3.3 is the interim, and does not satisfy §4.7. **done** permits it to remain a named gap.
- **Step 6 — scheduler / async tier (#266).** Deferred until a push feature needs it: `$/cancelRequest` for superseded work, debounced `publishDiagnostics`, and a bounded background scheduler that feature-detects `pcntl` / `ext-parallel` with a synchronous fallback.
- **Wave 3 — member kind, access context, intent detection.** The axes the 2026-08-10 RFC amendment added (RFC 1 §3.1, Appendix A "target" rows). Property hooks and asymmetric visibility wait on the first two. Sliced after the **done** gate — and each ends in a rule, per the goal above.
- **Feature-matrix runner.** A fixture-scenario × handler-registry grid, every cell asserting that handler's observable or registering not-applicable, an unregistered cell failing. Deprioritized 2026-08-10; revisit after the Step 4 drain. Worth reconsidering sooner than that priority implies — it is the cross-feature agreement net, and the same shape as the §5.1 grid that has already caught real gaps.

## Issue wiring

`Closes` is assigned when a slice issue is created, after a reviewer reads the issue — never inferred from a title.

- **#181** is not closable before **function-search**: its acceptance asks for these symbols in hover *and completion*, not merely resolvable, and it asks for a startup-time benchmark that S3.7d's eager index makes a live question. S3.7d pinned the parse *count*, which is not that measurement.
- **#317** needs rewriting before it can be wired to anything — see **completion-kind-collapse**.
- **#295** (Visibility enum) wants a small cleanup slice, not yet placed.
