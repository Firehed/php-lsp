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
composer parity-coverage
```

runs only the four surface tests with coverage restricted to the migrated surface
classes, so an unexecuted line is a **corpus gap** — a behavioral edge the corpus
does not exercise — surfaced before the harness is trusted. The corpus drives
every surface class to 100% except a handful of defensive guards that no realistic
project input can reach:

- parser `ast === null` guards (`DocumentIndexer`, `DefaultClassRepository`): the
  parser uses an error-*collecting* handler, so broken code yields a partial AST,
  never null;
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
