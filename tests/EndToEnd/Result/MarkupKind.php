<?php

declare(strict_types=1);

namespace Firehed\PhpLsp\Tests\EndToEnd\Result;

/**
 * [LSP] MarkupKind.
 */
enum MarkupKind: string
{
    case PlainText = 'plaintext';
    case Markdown = 'markdown';
}
