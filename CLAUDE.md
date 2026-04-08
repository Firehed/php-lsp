# CLAUDE.md

## Quick Start

```bash
composer check              # PHPStan + tests (run before commits)
composer test -- --filter X # Run specific tests
```

## Project Structure

- `src/Handler/` — LSP request handlers (completion, hover, definition, etc.)
- `src/Index/` — Symbol indexing and workspace scanning
- `src/Document/` — Open document management
- `docs/features/` — Feature status documentation

## Development Workflow

- GitHub issues are the source of truth for feature specs
- Update `docs/features/*.md` when merging features
- Run `composer check` before commits

## Completion System

See `docs/features/completion.md` for current capabilities.

Architecture: regex-based context detection in `CompletionHandler`. Determines completion type ($this->, static, new, function) and delegates to internal methods.

## LSP Protocol

Server communicates over stdio. Test with any LSP client; `docs/vim-ale.md` has Vim setup notes.
