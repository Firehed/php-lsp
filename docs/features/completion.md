# Completion Features

This document tracks the current state of code completion in php-lsp.

## Supported Completions

| Context | Trigger | What's Suggested | Status |
|---------|---------|------------------|--------|
| `$this->` member access | `$this->` or `$this?->` | Methods and properties from current class + inherited via reflection | ✅ Working |
| Typed variable member access | `$user->` or `$user?->` | Public methods and properties when type is known (parameter types, `new` expressions) | ✅ Working |
| Variable completions | `$log` | Local variables, parameters, `$this` in methods | ✅ Working |
| Static access | `ClassName::` | Static methods, constants, static properties (visibility-aware) | ✅ Working |
| `new` expression | `new ` | Classes from composer classmap | ✅ Working |
| Function calls | identifier at expression start | Built-in PHP functions + file-local functions | ✅ Working |

## Limitations

- **Visibility**: Typed variable completions only show public members. Use `$this->` for protected/private access within the class.

## Not Yet Supported

| Context | Example | Notes |
|---------|---------|-------|
| Keywords | `fore` → `foreach` | No language keyword suggestions |
| Array keys | `$config['` | No key suggestions from array shapes |
| Docblock tags | `@par` → `@param` | No PHPDoc completion |
| Snippets | Method inserting `()` | No snippet support in completion items |
| Auto-import | Suggesting FQCN with use statement insertion | No additional text edits |

## Architecture

```
CompletionHandler
├── AST-based context detection (via CompletionContextResolver)
│   ├── $this-> and $this?-> member completions
│   ├── $variable-> and $variable?-> typed variable completions
│   ├── ClassName:: static completions
│   └── self::/static::/parent:: completions
└── Regex-based fallback for other contexts
    ├── $var variable completions
    ├── new ClassName completions
    ├── Type hint completions
    └── Function/keyword completions
```

## Testing

```bash
composer test                    # Run all tests
composer test -- --filter Completion  # Run completion tests only
composer check                   # PHPStan + tests
```
