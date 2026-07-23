<?php

declare(strict_types=1);

namespace Firehed\PhpLsp\Completion;

/**
 * How a completion item's inserted text is interpreted, per [LSP] "Language
 * Features → Completion" (`InsertTextFormat`). `Snippet` enables tab-stop syntax
 * such as `$0`; it is only emitted when the client declares snippet support
 * (RFC 1 §4.8).
 */
enum InsertTextFormat: int
{
    case PlainText = 1;
    case Snippet = 2;
}
