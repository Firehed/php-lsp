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
- `src/Repository/` — Class and member resolution (see Architecture below)
- `src/Domain/` — Domain objects representing code constructs
- `src/Index/` — Symbol indexing and workspace scanning
- `src/Document/` — Open document management
- `src/Utility/` — AST helpers (ScopeFinder, TypeFactory, DocblockParser)
- `src/Completion/` — Completion context detection (CompletionContextResolver)
- `docs/features/` — Feature status documentation

## Architecture

### Repository Pattern

Class and member information flows through a repository layer:

- **ClassRepository** (`DefaultClassRepository`) — Resolves `ClassInfo` by FQN. Resolution order: open documents → locate & parse from filesystem → reflection fallback for built-in classes.
- **MemberResolver** — Finds methods/properties on a class, traversing inheritance chain. Returns domain objects (`MethodInfo`, `PropertyInfo`).
- **ClassInfoFactory** (`DefaultClassInfoFactory`) — Creates `ClassInfo` from AST nodes or reflection.

### Domain Objects

Typed representations of code constructs in `src/Domain/`:

- `ClassInfo` — Class/interface/trait/enum metadata (methods, properties, constants, inheritance)
- `MethodInfo`, `PropertyInfo`, `ConstantInfo`, `EnumCaseInfo` — Member metadata
- `ParameterInfo`, `FunctionInfo` — Function/method parameter details
- `Visibility` enum — Public/protected/private with comparison logic
- `ClassName`, `MethodName`, `PropertyName` — Typed identifiers

Domain objects implement `Formattable` for consistent signature formatting across handlers.

### Type System

The `Type` interface represents PHP types throughout the codebase. Implementations:

- `ClassName` — Class/interface/trait/enum types (also serves as class identity)
- `PrimitiveType` — Built-in types (`string`, `int`, `bool`, `null`, `mixed`, etc.)
- `UnionType` — Union types (`A|B`); nullable types stored internally as `[A, null]` but format as `?A`
- `IntersectionType` — Intersection types (`A&B`)

Key methods:
- `format(): string` — Display representation (`?User` for nullable, `User|Admin` for unions)
- `getResolvableClassNames(): list<ClassName>` — Classes for member lookup (filters out primitives)
- `isNullable(): bool` — Whether the type includes null

**Never store types as strings.** Use `TypeFactory::fromNode()` or `TypeFactory::fromReflection()` to create Type objects at parse time. Use `Type::format()` only for display.

### Guidelines for New Code

- **Keep code DRY.** Be on the lookout for existing tools that will solve your problem; NEVER copy-and-paste. Extract repeated logic aggressively.
- **Use repositories, not direct reflection.** `MemberResolver::findMethod()` handles inheritance; raw `ReflectionClass` does not integrate with open documents.
- **Use domain objects.** Return `MethodInfo`/`PropertyInfo` from lookups, not raw AST nodes or reflection objects.
- **Add factory methods to domain objects** for new construction patterns (e.g., `FunctionInfo::fromNode()`, `FunctionInfo::fromReflection()`).
- **Check existing utilities before writing AST traversal.** Search `ScopeFinder` and handlers for similar patterns before creating new `NodeVisitorAbstract` implementations. Duplicate traversal logic should be extracted to utilities.
- **Use `ExpressionTypeResolver` for expression types.** It wraps `TypeResolverInterface` and handles special cases like `$this`. Handlers should use it consistently rather than calling `TypeResolverInterface` directly.
- **Use `MemberAccessResolver` for member access.** It handles both `->` and `?->` operators and resolves types via the shared utilities. Handlers should use it instead of duplicating type resolution logic.
- **Use `Type` objects, not strings.** Store and pass types as `Type` instances. Use `TypeFactory` to create them from AST or reflection. Call `format()` only at display time.

### Remaining Utilities

- `ScopeFinder` — Finds enclosing class/method scope in AST, resolves names, finds functions
- `DocblockParser` — Extracts description from docblocks
- `ExpressionTypeResolver` — Resolves expression types (wraps TypeResolverInterface, handles `$this`)
- `TypeFactory` — Creates Type domain objects from AST nodes and reflection
- `MemberAccessResolver` — Resolves method calls and property fetches to domain objects; handles both `->` and `?->` operators

## Development Workflow

- GitHub issues are the source of truth for feature specs
  - Before starting on a feature, verify that it hasn't already been impemented
  - Resolve any ambiguity or conflict BEFORE starting to write a line of code
- Update `docs/features/*.md` when merging features
- Run `composer check` before commits
- `composer.lock` is gitignored — do not attempt to stage or commit it

## Completion System

See `docs/features/completion.md` for current capabilities.

Architecture: `CompletionContextResolver` uses AST analysis to detect member/static access contexts (handles both `->` and `?->` automatically). Regex-based detection remains for other contexts (variables, type hints, keywords).

**Prefer AST-based context detection over regex.** The parser's error recovery produces usable AST even for incomplete code like `$this->`. AST detection handles operator variants (e.g., `->` vs `?->`) automatically without pattern duplication.

## LSP Protocol

Server communicates over stdio. Test with any LSP client; `docs/vim-ale.md` has Vim setup notes.

## Project Guidelines

- Aggressively, proactively refactor. Consistent behavior is paramount to long-term success.
- ALWAYS follow TDD.
- Test coverage MUST be 100% for all new code. NO EXCEPTIONS. If a branch should be unreachable, it should either be rewritten to be eliminated or, if impractical, throw a logic exception and marked for coverage ignore. Prefer to eliminate the dead branch.
- Update documentation and guidelines when making changes. It is critical to keep this up to date to avoid drift and redundant work.
