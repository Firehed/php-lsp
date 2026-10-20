# Contributing

## Development Workflow

- Follow TDD. Coverage must be 100% for new code. Rewrite a dead branch out instead of excluding it.
- Only the unit suite measures coverage (`composer coverage`), and a test counts only for what its `#[Covers…]` attributes name.
- A unit test covers the one class it tests. A data type with any code gets its own test rather than being covered through a service that uses it.
- Update `docs/features/*.md` when merging features.
- Run `composer test` before commits.
- `composer.lock` is gitignored. Do not stage or commit it.
- One route per fact. Before writing code that answers a question (what a symbol is, where it is declared, what type an expression has), check whether the codebase already answers it. If it does, extend that answer even when it is incomplete, moving it first if it sits where the design says it should not; if it does not, add the one place that will.
- Before a pull request leaves draft, a fresh agent runs the `pr-review` skill on it.

## Code Style

PHPCS enforces PSR-12 at minimum; generally follow the latest "PER" style guide as allowed by the minimum PHP version defined in composer.json.

Leverage modern PHP features where appropriate and useful:
- Asymmetric visibility
- Interface properties
- Property hooks
- Attributes
- Named arguments

The above list is non-exhaustive. `composer.json` lists the canonical minimum version (currently 8.4+).

## Testing

Do not write tests with inline PHP code.
Use the fixture tooling when a test covers code or file handling.
The one exception is the text an end-to-end script types into an open document (`tests/EndToEnd/scripts/`): keep it to a short fragment, as a person would type it.

### Fixtures

Fixtures live under `tests/Fixtures/` as a nested Composer project.
Run `composer install` there before the suite, and again after adding files outside the PSR-4 or PSR-0 paths.

Reuse existing fixtures before adding new ones.
Shared domain fixtures live in `src/Domain/`, `src/Enum/`, `src/Inheritance/`, and similar; import and extend them.
Handler-specific fixtures with cursor markers live in `src/Completion/`, `src/Hover/`, `src/Definition/`, and `src/SignatureHelp/`.
Files that intentionally break PSR-4, such as multi-class files, live in top-level directories like `MultiClass/`, not under `src/`.

Additive changes to shared fixtures are fine.
Renames, removals, and signature changes need coordination.

### Helpers

`LoadsFixturesTrait` loads a fixture's text for unit tests:

```php
$content = $this->loadFixture('src/TypeInference/NewKeywords.php');
```

### Cursor markers

`/*|marker*/` puts the cursor before the marker.
Use it for incomplete code such as `$this->`, where the parser must recover.
Each incomplete statement needs its own method; two in one method confuse parser recovery.

`//hover:marker` puts the cursor on the last member access or function call on that line.
Use it for complete code such as `$this->method()`.
