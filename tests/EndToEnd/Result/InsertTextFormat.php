<?php

declare(strict_types=1);

namespace Firehed\PhpLsp\Tests\EndToEnd\Result;

/**
 * [LSP] InsertTextFormat.
 */
enum InsertTextFormat: int
{
    case PlainText = 1;
    case Snippet = 2;
}
