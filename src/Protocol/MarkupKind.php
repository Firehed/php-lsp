<?php

declare(strict_types=1);

namespace Firehed\PhpLsp\Protocol;

/**
 * The content formats a client can render in result literals such as `Hover`
 * and `CompletionItem`, per [LSP] "Basic JSON Structures" (`MarkupContent`).
 */
enum MarkupKind: string
{
    case Markdown = 'markdown';
    case PlainText = 'plaintext';
}
