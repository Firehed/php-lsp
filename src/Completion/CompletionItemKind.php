<?php

declare(strict_types=1);

namespace Firehed\PhpLsp\Completion;

/**
 * LSP CompletionItemKind values.
 *
 * @see https://microsoft.github.io/language-server-protocol/specifications/lsp/3.17/specification/#completionItemKind
 */
enum CompletionItemKind: int
{
    case Method = 2;
    case Function = 3;
    case Field = 5;
    case Module = 9;
    case Variable = 6;
    case Class_ = 7;
    case Property = 10;
    case Keyword = 14;
    case EnumMember = 20;
    case Constant = 21;
}
