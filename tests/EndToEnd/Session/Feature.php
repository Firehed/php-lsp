<?php

declare(strict_types=1);

namespace Firehed\PhpLsp\Tests\EndToEnd\Session;

/**
 * The requests an editor makes at a cursor. All take the same parameters
 * ([LSP] TextDocumentPositionParams).
 */
enum Feature: string
{
    case Completion = 'textDocument/completion';
    case Definition = 'textDocument/definition';
    case Hover = 'textDocument/hover';
    case SignatureHelp = 'textDocument/signatureHelp';
}
