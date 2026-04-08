# Completion Features

This document tracks the current state of code completion in php-lsp.

## Supported Completions

| Context | Trigger | What's Suggested | Status |
|---------|---------|------------------|--------|
| `$this->` member access | `$this->` or `$this->prefix` | Methods and properties from current class + inherited via reflection | ✅ Working |
| Static access | `ClassName::` | Static methods, constants, static properties | ✅ Working |
| `new` expression | `new ` | Classes from composer classmap | ✅ Working |
| Function calls | identifier at expression start | Built-in PHP functions + file-local functions | ✅ Working |

## Not Yet Supported

| Context | Example | Notes |
|---------|---------|-------|
| Variable completions | `$log` → `$logger` | No local variable or parameter suggestions |
| Member access on variables | `$logger->` | Only `$this->` works, not arbitrary typed variables |
| Keywords | `fore` → `foreach` | No language keyword suggestions |
| Array keys | `$config['` | No key suggestions from array shapes |
| Docblock tags | `@par` → `@param` | No PHPDoc completion |
| Snippets | Method inserting `()` | No snippet support in completion items |
| Auto-import | Suggesting FQCN with use statement insertion | No additional text edits |

## Architecture

```
CompletionHandler
└── Regex-based context detection in getCompletionItems()
    ├── $this-> member completions
    ├── ClassName:: static completions
    ├── new ClassName completions
    └── Function name completions
```

## Testing

```bash
composer test                    # Run all tests
composer test -- --filter Completion  # Run completion tests only
composer check                   # PHPStan + tests
```
