# Per-surface parity harness (Plan 0002, Step P)

This harness gates the behavior-preserving migrations in Steps 2–4 of
`docs/architecture/0002-execution-plan.md`. `TypeGraphParityTest` covers member
resolution; this covers the surfaces those steps move consumers onto:

| Surface | Production entry point | Golden |
|---|---|---|
| class-like lookup | `SymbolSource::lookupClassLike()` | `goldens/class-like-lookup.json` |
| namespace enumeration | `SymbolSource::childrenOf()` | `goldens/children-of.json` |
| prefix search | `SymbolIndex::findByPrefix()` | `goldens/prefix-search.json` |
| document write path | open/update/close symbol state | `goldens/write-path.json` |
| function completion | `FunctionCandidates::find()` | `goldens/function-surface.json` |

The function surface is frozen ahead of the step that changes it: Step 3b moves
function completion off its direct `get_defined_functions()` call and onto
`SymbolSource::search`, adding project reach it does not have today. The new reach
is proven by new fixtures in that slice; the golden is the *preservation* half of
its acceptance — built-in and open-document function completion must survive the
migration unchanged.

Alongside it, `BuiltinFunctionParityTest` is an **oracle, not a golden**: it asserts
the built-in backend enumerates exactly the functions `get_defined_functions()`
reports, the way `TypeGraphParityTest` uses reflection as the oracle for members.
That is what makes the version-fragile half of the function surface checkable at
all — the set cannot be frozen, but it can be compared against the runtime truth
the production path reads today.

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
stable-subset assertions instead of being frozen. Each surface picks the queries
that keep that true: `children-of` enumerates only namespaces holding no reflected
symbols, and `function-surface` queries only prefixes narrow enough to match a
single long-stable core function (`arr` shifts between 8.3 and 8.5; `array_map`
does not). Absolute paths are relativized to the repo root so a golden does not
embed the machine it was captured on.

## Surface coverage (the corpus-gap check)

```bash
composer parity-coverage   # phpunit tests/Parity --coverage-text
```

runs the parity tests under coverage (a path selector on the normal config, no
separate config file). Read the rows for the surface classes in the table above: an
unexecuted line there is a **corpus gap** — a behavioral edge the corpus does not
exercise — to surface before the harness is trusted.

The corpus drives the *lookup* and *enumeration* surfaces through the
`CompositeSymbolSource` and its backends. Two things it deliberately does **not**
drive, so their lines show here as unexecuted but are fully covered elsewhere: the
per-backend `search` (empty on the workspace, vendor, and built-in backends) and the
composite's prefix-search merge — prefix-search parity runs against `SymbolIndex`
directly, and the backends' own unit tests exercise the rest.

Within the surfaces the corpus does drive, a handful of defensive lines stay
uncovered or are marked `@codeCoverageIgnore` — all unreachable for realistic project
input, one a known corpus gap:

- parser `ast === null` guard (`DocumentIndexer`): a known **corpus gap**, not dead
  code. `ParserService::parseContent` returns null only from its
  `catch (\PhpParser\Error)` arm — a parse that *throws* despite the error-collecting
  handler; the corpus's broken fixture instead yields a partial AST, so the early
  return is reachable but left un-exercised here (a bare early return, low risk);
- the IO-failure guards in `FilesystemBackend` — `file_get_contents` failing after
  `is_readable` succeeds, and a parse that throws despite error recovery — are marked
  `@codeCoverageIgnore`: unreachable for a located, well-formed file;
- the `Constant` arm of `nameKindOf` (`WorkspaceNamespaceSource`): `SymbolExtractor`
  does not emit constant symbols, so no workspace input reaches it;
- an autoload map pointing at a missing directory, or a non-`.php` file in a scanned
  directory (`ComposerNamespaceSource`) — reachable only via a synthetic autoload
  map, which the dedicated unit tests exercise, not a real project corpus.

Per Plan 0002, branch-level verification that the corpus actually catches a
regression — naming a mutation and confirming a golden goes red — is the job of the
`/review-slice` adversarial pass, not this line-coverage measurement.
