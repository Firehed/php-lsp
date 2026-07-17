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
| Namespace navigation | `new \Ps`, `catch (\E`, `function f(\Ps` | Vendor/built-in class-likes and child namespaces from the catalog, walked one segment at a time. Works on absolute (`\`-rooted) names and on relative prefixes resolved through a `use` import or the current namespace (`use App\Model\Env;` or being inside `App\Model` → `new Env\R`) | ✅ Working |

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
(`\Other\Thing`) or an import. Built-in and vendor class-likes are reached by namespace
navigation on an absolute name (see below). Functions (#239) and constants (#317) get the
same namespace-correct treatment in their own issues.

### Namespace navigation

Typing an absolute name (`new \Ps`, `catch (\E`, `function f(\Ps`) walks the namespace
tree, sourced from the catalog so vendor and built-in symbols appear without their files
being open. At each step the current namespace contributes both its class-likes (offered
by leaf name) and its child namespaces as `Module` nodes. A node inserts the next segment
with its trailing separator (`User\`) — the separator is in the inserted text, not just the
label, because clients render the text they insert (Vim/ale shows `textEdit.newText`, not
`label`), so a bare segment would be indistinguishable from a same-named class. Typing the
following segment then fires completion one level deeper. A namespace with five or fewer
members is inlined
(its contents offered directly, qualified by the segment) instead of a node, so a class and
a same-named companion namespace are offered side by side. Directly-insertable symbols rank
above nodes, and the result is capped with `isIncomplete` set so the client re-queries as
the prefix narrows. Catalog candidates are resolved before being offered, so a
`functions.php` picked up from a directory listing is never offered as a class.

Navigation also works from a **relative prefix** whose first segment is a `use` import or a
child of the current namespace. With `use App\Model\Env;` (or from inside `App\Model`),
`new Env\` and `new Env\R` navigate `App\Model\Env` and offer its children, and `new Env`
descends into it directly. A relative name behaves **identically to the absolute form** once
the first segment is resolved — the `\` only changes how the root is qualified — so `new Env`
inlines a small target (offering `Env\Repository` …) and nodes a large one (`Env\`), exactly
as `new \App\Model\Env` would. Discovery is catalog-sourced (no open file needed), and — as
with absolute navigation — insertion is **leaf-relative**: at `new Env\R` only `Repository`
is inserted, replacing the segment after the last `\`, so the typed `Env\` stands and is
never duplicated (the FQCN is carried in the item's detail). The position filter still
applies (an interface child is offered as a type hint but not after `new`), and only an
imported alias or a real current-namespace child opens a namespace — an unrelated prefix
reaches nothing.

Leaf-relative insertion is also what keeps this working in editors that don't apply the LSP
`textEdit` range and instead replace the word under the cursor (e.g. Vim/ale, see
[dense-analysis/ale#4274](https://github.com/dense-analysis/ale/issues/4274)); the inserted
text is aligned to that word boundary rather than spanning the whole qualified name.

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
