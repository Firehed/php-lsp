# Per-surface parity harness (Plan 0002, Step P)

This harness gates the behavior-preserving migrations in Steps 2–4 of
`docs/architecture/0002-execution-plan.md`. `TypeGraphParityTest` covers member
resolution; this covers the four surfaces those steps move consumers onto:

| Surface | Production entry point | Golden |
|---|---|---|
| class-like lookup | `ClassRepository::get()` | `goldens/class-like-lookup.json` |
| namespace enumeration | `NamespaceCatalog::childrenOf()` | `goldens/children-of.json` |
| prefix search | `SymbolIndex::findByPrefix()` | `goldens/prefix-search.json` |
| document write path | open/update/close symbol state | `goldens/write-path.json` |

Each surface's observable output over a curated fixture corpus is captured once,
spot-audited, committed, and diffed on every run. A behavior-preserving step must
reproduce its goldens byte for byte; a step that intends to change one surface
recaptures **only** that surface's golden while the others stay frozen.

## Maintenance contract (read this before touching a fixture or a surface class)

- **Permanent, not scaffolding.** This harness is never deleted. Plan 0002's
  Teardown ledger does not list it, and Step Z requires it green. Treat it like
  `TypeGraphParityTest` — a standing regression net, not a migration crutch.
- **No scheduled cleanup.** A golden changes *only* when a surface's observable
  output legitimately changes. There is nothing to prune or refresh periodically.
- **A red golden is a question, not a chore.** Ask: *did I mean to change this
  surface's output?*
  - **No** → you have a regression. Fix the code, not the golden.
  - **Yes** (a Step 3b feature, or a deliberate fixture change) → recapture only the
    affected surface (below) and read every changed line before committing.
- **Editing a fixture the harness uses changes its golden.** Each surface test names
  its corpus (`CORPUS` / `INDEXED_DOCUMENTS` / the query lists). Adding a method to
  `User`, or a file under `Fixtures\Model` or `Fixtures\Catalog`, will shift the
  relevant golden — that is expected; recapture and review. Avoid growing the parity
  corpus for unrelated tests: it is deliberately small and stable so unrelated
  fixture churn does not ripple here.
- **A refactor that moves a surface class needs no harness change.** The goldens
  assert *output*, so they ride a rename or relocation (e.g. the `SymbolSource`
  facade in Step 2, the `SymbolResolver` decomposition in Step 4) unchanged; if a
  golden *does* diff during a "behavior-preserving" step, the refactor changed
  behavior. There is no separate config or surface-file list to keep in sync — the
  surfaces are the classes in the table above.
- **What it does not guard.** These are the *knowledge* surfaces (declared symbols,
  enumeration). Positional / type-inference behavior — flow narrowing, hover of an
  inferred variable, generics *in expressions* — is a different layer and does not
  touch these goldens; it is guarded by its own tests.

## Updating a golden

```bash
UPDATE_GOLDENS=1 composer unit -- --filter <SurfaceParityTest>
```

Then **read the diff** before committing. A golden captured wrong is green
forever — branch coverage proves a line executed, not that the frozen expectation
is correct. Recapture is deliberate, not a way to make a red run pass.

## Determinism

Goldens are frozen only over inputs that are identical across the CI PHP matrix
(8.3 / 8.4 / 8.5): in-repo fixtures and the locked `psr/http-message` dependency.
Built-in symbols (reflection) are version-fragile, so they are covered by
stable-subset assertions instead of being frozen. Absolute paths are relativized
to the repo root so a golden does not embed the machine it was captured on.

## Surface coverage (the corpus-gap check)

```bash
composer parity-coverage   # phpunit tests/Parity --coverage-text
```

runs the parity tests under coverage (a path selector on the normal config, no
separate config file). Read the rows for the surface classes in the table above: an
unexecuted line there is a **corpus gap** — a behavioral edge the corpus does not
exercise — to surface before the harness is trusted. The corpus drives every surface
class to 100% except a handful of defensive lines — most unreachable for any realistic
project input, one a known corpus gap:

- parser `ast === null` guards (`DocumentIndexer`, `DefaultClassRepository`): a known
  **corpus gap**, not dead code. `ParserService::parseContent` returns null only from
  its `catch (\PhpParser\Error)` arm — a parse that *throws* despite the
  error-collecting handler; the corpus's broken fixture instead yields a partial AST,
  so these early returns are reachable but left un-exercised here (a bare early return,
  low risk);
- `file_get_contents` failing after `is_readable` succeeds (`DefaultClassRepository`);
- an anonymous class reached while scanning a located file for a *different* named
  class (`DefaultClassRepository`): the first matching declaration stops the walk;
- the `Constant` arm of `nameKindOf` (`WorkspaceNamespaceSource`): `SymbolExtractor`
  does not emit constant symbols, so no workspace input reaches it;
- an autoload map pointing at a missing directory, or a non-`.php` file in a scanned
  directory (`ComposerNamespaceSource`) — reachable only via a synthetic autoload
  map, which the dedicated unit tests exercise, not a real project corpus.

Per Plan 0002, branch-level verification that the corpus actually catches a
regression — naming a mutation and confirming a golden goes red — is the job of the
`/review-slice` adversarial pass, not this line-coverage measurement.
