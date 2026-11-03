# Contributing

## Architecture

The project's architectural goals are captured in `docs/adrs`.
Contributions must be in line with the ADRs.
Deviations must be justified and should be accompanied by a replacement or supplemental ADR.

Note: ADRs capture intent, not necessarily the current state of the codebase.
If you encounter a conflict between the current structure and the ADRs when contributing, the ADRs should guide the new code.

## Development Workflow

- Follow TDD. Coverage must be 100% for new code. Rewrite a dead branch out instead of excluding it.
- Only the unit suite measures coverage (`composer coverage`), and a test counts only for what its `#[Covers…]` attributes name.
- A unit test covers the one class it tests. A data type with any code gets its own test rather than being covered through a service that uses it.
- Update `docs/features/*.md` when merging features.
- Run `composer test` before commits.
- `composer.lock` is gitignored. Do not stage or commit it.

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

### Mutation testing

`composer mutation` makes small changes to each line of `src/` the branch changed, and fails if any change goes unnoticed by both the unit suite and PHPStan.
It compares the working tree with `origin/main`, so commit first and keep `origin/main` current; a fork's `origin` must be this repository.
CI runs it on every pull request.

A surviving change is a missing test, unless no input could tell the changed code from the original.
In that case, rewrite the code so the change cannot be made.
If that is not possible, add an ignore entry for the `Class::method` (never a line number) to `infection.json5`, with the maintainer's approval in the pull request.

A test names the behavior it checks, never the mutation it catches.

## Code Reviews

Reviewing code should focus on things not covered by CI or automated tooling:

- Does the code make sense in the context of the project?
- Does it agree with the actual Language Server Protocol specs?
- Does it adhere to the architectural guidance covered in ADR docs (`docs/adrs`)?
- Can the code be simplified, DRYed out, or better follow general software best practices?
