# Completion Features

This document tracks the current state of code completion in php-lsp.

## Supported Completions

| Context | Trigger | What's Suggested | Status |
|---------|---------|------------------|--------|
| `$this->` member access | `$this->` or `$this?->` | Methods and properties from current class + inherited via reflection | ✅ Working |
| Typed variable member access | `$user->` or `$user?->` | Public methods and properties when type is known (parameter types, `new` expressions) | ✅ Working |
| Variable completions | `$log` | Local variables, parameters, `$this` in methods, and file-level variables in procedural code | ✅ Working |
| Static access | `ClassName::` | Static methods, constants, static properties (visibility-aware) | ✅ Working |
| `new` expression | `new ` | Classes from composer classmap | ✅ Working |
| Function calls | identifier at expression start | Built-in PHP functions + file-local functions | ✅ Working |
| Keywords | `fore` → `foreach` | Context-aware PHP keywords (statement/expression start, class body, after visibility) | ✅ Working |
| `implements` list | `class Foo implements Ba` | Interfaces only (from imports + workspace index); classes, traits, and functions excluded | ✅ Working |
| `interface … extends` list | `interface Foo extends Ba` | Interfaces only (from imports + workspace index); distinguished from `class … extends` by the `interface` keyword | ✅ Working |
| Attribute position | `#[Ro` | Attribute classes only (from imports + workspace index); classes, interfaces, traits, enums, and functions excluded. Grouped (`#[A, Ba`) supported; target-aware filtering is not yet (#252) | ✅ Working |
| Attribute arguments | `#[Route(` | Constructor named arguments, like a normal call (an attribute is a constructor call on its class); signature help shows the constructor | ✅ Working |

All of the above work identically in class methods, free functions, and
file-level (procedural) code — variable and member resolution use the enclosing
lexical scope, which includes global scope.

## Limitations

- **Visibility**: Typed variable completions only show public members. Use `$this->` for protected/private access within the class.
- **Union types**: Completions on union types (e.g., `User|Admin`) show only members that exist on ALL types in the union (intersection of members). This is type-safe but may show fewer completions than expected. For intersection types (`User&Admin`), all members from all types are shown.

## Not Yet Supported

| Context | Example | Notes |
|---------|---------|-------|
| Array keys | `$config['` | No key suggestions from array shapes |
| Docblock tags | `@par` → `@param` | No PHPDoc completion |
| Snippets | Method inserting `()` | No snippet support in completion items |
| Auto-import | Suggesting FQCN with use statement insertion | No additional text edits |

## Architecture

`CompletionHandler` is a coordinator: it detects the position, delegates to a
completion source, then merges and deduplicates. It never parses documents itself.

```
CompletionHandler (coordinator)
├── MemberCandidates                  → -> ?-> :: (member/static/parent access)
│     via CodeResolver (AST-first, text fallback for mid-edit code)
├── call context (CodeResolver::getCallContext)
│     ├── NamedArgumentCandidates     → name: arguments
│     ├── VariableCandidates          → $var in argument position
│     └── after name: (value position) → KeywordCandidates (expression)
│                                         + ClassCandidates (any)
└── CompletionClassifier (text-based) → typed CompletionKind, dispatched to:
      ├── VariableCandidates          → $var
      ├── ClassCandidates             → new X / expression / type hints / implements + interface extends / attributes (by ClassCandidateFilter)
      ├── FunctionCandidates          → user-defined + built-in functions
      ├── KeywordCandidates           → keywords (by KeywordGroup)
      └── BuiltinTypeCandidates       → built-in type hints
```

Sources live in `src/Completion/*Candidates`; each owns lookup + prefix filter +
item construction (`CompletionItemFactory`). Parser-derived data (imports, file
functions, members, variables, types) flows through `CodeResolver`, so sources are
agnostic to the parsing strategy. Detection stays text/token-based (`ContextDetector`,
`CompletionClassifier`) so completion keeps working on temporarily-broken code.

## Testing

```bash
composer test                    # Run all tests
composer test -- --filter Completion  # Run completion tests only
composer check                   # PHPStan + tests
```
