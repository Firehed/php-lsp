# CLAUDE.md

## Quick Start

```bash
composer check # PHPStan + tests + PHPCS (run before commits)
composer phpunit -- --filter X # Run specific tests
composer phpstan -- --error-format=raw --no-progress # run phpstan
composer phpstan -- --error-format=raw --no-progress path/to/analyze # run phpstan on a specific path
composer phpcs -- -q --report=emacs # run code style checks (PSR-12)
```

## Project Structure

- `src/Handler/` — LSP request handlers (completion, hover, definition, etc.)
- `src/Index/` — Symbol indexing and workspace scanning
- `src/Document/` — Open document management
- `docs/features/` — Feature status documentation

## Development Workflow

- GitHub issues are the source of truth for feature specs
  - Before starting on a feature, verify that it hasn't already been impemented
  - Resolve any ambiguity or conflict BEFORE starting to write a line of code
- Update `docs/features/*.md` when merging features
- Run `composer check` before commits
- `composer.lock` is gitignored — do not attempt to stage or commit it

## Completion System

See `docs/features/completion.md` for current capabilities.

Architecture: regex-based context detection in `CompletionHandler`. Determines completion type ($this->, static, new, function) and delegates to internal methods.

## LSP Protocol

Server communicates over stdio. Test with any LSP client; `docs/vim-ale.md` has Vim setup notes.

## Project Guidelines

- Aggressively, proactively refactor. Consistent behavior is paramount to long-term success.
- ALWAYS follow TDD.
- Update documentation and guidelines when making changes. It is critical to keep this up to date to avoid drift and redundant work.
