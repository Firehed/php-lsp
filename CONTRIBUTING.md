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

`composer mutation` runs [Infection](https://infection.github.io/) on the lines you changed in `src/`.
It fails if a mutant escapes, which means neither the unit tests nor PHPStan caught it.
CI runs the same check on pull requests.

It compares your working tree against `origin/main`, so commit and fetch before running it.
If you work from a fork, `origin` needs to be this repository.

When a mutant escapes, add a test that catches it.
If the mutation doesn't change behavior, so no test could catch it, rewrite the code so the mutation isn't possible.
If you can't, ask in the pull request about ignoring that `Class::method` in `infection.json5`.
Don't ignore by line number.

Name tests after the behavior they check, not the mutant they catch.

## Code Reviews

Reviewing code should focus on things not covered by CI or automated tooling:

- Does the code make sense in the context of the project?
- Does it agree with the actual Language Server Protocol specs?
- Does it adhere to the architectural guidance covered in ADR docs (`docs/adrs`)?
- Can the code be simplified, DRYed out, or better follow general software best practices?
