# Completion Features

This document tracks the current state of code completion in php-lsp.

## Supported Completions

| Context | Trigger | What's Suggested | Status |
|---------|---------|------------------|--------|
| `$this->` member access | `$this->` or `$this?->` | Methods and properties from current class + inherited via reflection | ✅ Working |
| Typed variable member access | `$user->` or `$user?->` | Public methods and properties when type is known (parameter types, `new` expressions) | ✅ Working |
| Variable completions | `$log` | Local variables, parameters, `$this` in methods, and file-level variables in procedural code | ✅ Working |
| Static access | `ClassName::` | Static methods, constants, static properties (visibility-aware) | ✅ Working |
| `new` expression | `new ` | Instantiable classes from imports + workspace index, with namespace-correct references | ✅ Working |
| Function calls | identifier at expression start | Built-in PHP functions + file-local functions | ✅ Working |
| Keywords | `fore` → `foreach` | Context-aware PHP keywords (statement/expression start, class body, after visibility) | ✅ Working |
| `implements` list | `class Foo implements Ba` | Interfaces only (from imports + workspace index); classes, traits, and functions excluded | ✅ Working |
| `interface … extends` list | `interface Foo extends Ba` | Interfaces only (from imports + workspace index); distinguished from `class … extends` by the `interface` keyword | ✅ Working |
| `class … extends` | `class Foo extends Ba` | Extendable (non-final) classes only (from imports + workspace index); final classes, interfaces, traits, enums, and functions excluded | ✅ Working |
| `catch` clause | `catch (Ba` or `catch (Foo \| Ba` | Throwable types only (from imports + workspace index): `Throwable` and any class or interface extending/implementing it. Multi-catch (`\|`-separated) supported; non-throwable types, functions, and keywords excluded | ✅ Working |
| Attribute position | `#[Ro` | Attribute classes only (from imports + workspace index); classes, interfaces, traits, enums, and functions excluded. Grouped (`#[A, Ba`) supported; target-aware filtering is not yet (#252) | ✅ Working |
| Attribute arguments | `#[Route(` | Constructor named arguments, like a normal call (an attribute is a constructor call on its class); signature help shows the constructor | ✅ Working |

All of the above work identically in class methods, free functions, and
file-level (procedural) code — variable and member resolution use the enclosing
lexical scope, which includes global scope.

Member completions cover the full type graph: members declared on the type itself,
plus those inherited through the parent chain, used traits (including traits that
use other traits), and implemented or extended interfaces at any depth. This holds
whether the type is read from an open document, parsed from disk, or reflected from
a built-in.

Class-like completions are **namespace-correct**: a candidate is offered only where a
reference resolves to it at the cursor, and is labelled with that reference. A class in
the current namespace is offered by its short name; one in a sub-namespace as a relative
reference (`Sub\Thing`); and a class in the namespace an import opens as a prefixed
reference (`use App\Model\User;` also offers `User\Repository`). A class with no
unqualified reference at the cursor — an unrelated, unimported namespace — is not offered,
rather than offered as a bare name that would insert broken code; reaching it is navigation
(`\Other\Thing`) or an import. Built-in and vendor class-likes are not sourced here yet;
they are reached by navigation (#330). Functions (#239) and constants (#317) get the same
namespace-correct treatment in their own issues.

## Limitations

- **Visibility**: Typed variable completions only show public members. Use `$this->` for protected/private access within the class.
- **Union types**: Completions on union types (e.g., `User|Admin`) show only members that exist on ALL types in the union (intersection of members). This is type-safe but may show fewer completions than expected. For intersection types (`User&Admin`), all members from all types are shown.
- **Trait conflict resolution**: `insteadof` and `as` adaptations are ignored (#73). Methods aliased with `as` are not offered, and when two traits declare the same method the first one listed wins regardless of `insteadof`.

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
      ├── ClassCandidates             → new X / expression / type hints / implements + extends / catch / attributes (by ClassCandidateFilter)
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
