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

This is an MVP implementation supporting only the basic LSP lifecycle:

- `initialize` - Returns server capabilities and info
- `initialized` - Notification acknowledgment
- `shutdown` - Prepares server for exit
- `exit` - Terminates the server

## Development

```bash
# Install dependencies
composer install

# Run tests and static analysis
composer check

# Run just tests
composer test

# Run just static analysis
composer analyze
```

## Editor Integration

See the [docs](docs/) directory for editor-specific setup guides.

## License

MIT
