# Build Manifest (slice registry)

    Status:   Draft — seeded through Wave 1 (Steps 0, 1, P, 2)
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
- Wave 2 (Step 3a/3b sub-slices, Step 4 positional-layer units, Step 5, Step 6) is
  appended when S2.* is `done`. Existing issues expected there: #239, #181, #317
  (Step 3b); #268, #301, #303, #73, #74 (enabled, own feature PRs); #266/#264/#265
  (later-step epics). #295 (Visibility enum) rides a cleanup slice.
