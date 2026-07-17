# php-lsp

A minimal Language Server Protocol (LSP) server for PHP.

## Requirements

- PHP 8.3+

## Installation

```bash
composer require firehed/php-lsp
```

## Usage

The server communicates via stdio:

```bash
./vendor/bin/php-lsp
```

## Current Capabilities

- **Completion** - Member access (`$this->`, `$var->`), static access (`Class::`), `new` expressions, function calls, variable names
- **Hover** - Type information and docblocks for methods, properties, functions, and classes
- **Go to Definition** - Jump to method, property, function, and class definitions
- **Signature Help** - Parameter hints when calling functions and methods

## Development

```bash
# Install dependencies
composer install

# Generate test fixture autoload
composer dump-autoload --working-dir=tests/Fixtures

# Run tests, static analysis, and linters
composer test

# Run just tests
composer unit

# Run just static analysis
composer phpstan

# Run just linters
composer phpcs
```

## Editor Integration

See the [docs](docs/) directory for editor-specific setup guides.

## License

MIT
