<?php

declare(strict_types=1);

namespace Firehed\PhpLsp\Parser\SyntaxSource;

/**
 * A {@see SyntaxSource} decorator whose state closes at the LSP message
 * boundary. Server's message loop clears it through this interface and never
 * names the decorator: any decorator wanting the same lifetime (a memo, a
 * per-message counter) implements it and joins the boundary without a new
 * hook.
 */
interface MessageScoped
{
    public function endMessage(): void;
}
