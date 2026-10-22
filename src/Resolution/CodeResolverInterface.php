<?php

declare(strict_types=1);

namespace Firehed\PhpLsp\Resolution;

use Firehed\PhpLsp\Document\TextDocument;

/**
 * Interface for code resolution services.
 *
 * Implementations provide symbol resolution, member access detection, and
 * related queries needed by LSP handlers.
 *
 * This abstraction allows different parsing strategies (PHP-Parser, tree-sitter)
 * to provide the same resolution capabilities.
 */
interface CodeResolverInterface
{
    /**
     * Detect member access context at cursor position.
     * Used by: Completion
     */
    public function getMemberAccessContext(
        TextDocument $document,
        int $line,
        int $character,
    ): ?MemberAccessContext;
}
