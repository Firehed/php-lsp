<?php

declare(strict_types=1);

namespace Firehed\PhpLsp\Resolution;

use Firehed\PhpLsp\Document\TextDocument;
use Firehed\PhpLsp\Domain\ClassName;
use Firehed\PhpLsp\Domain\Type;
use Firehed\PhpLsp\Domain\Visibility;

/**
 * Interface for code resolution services.
 *
 * Implementations provide symbol resolution, member access detection, and
 * related queries needed by LSP handlers.
 *
 * This abstraction allows different parsing strategies (PHP-Parser, tree-sitter)
 * to provide the same resolution capabilities.
 */
interface CodeResolver
{
    /**
     * Resolve symbol at cursor position.
     * Used by: Definition, Hover, TypeDefinition
     */
    public function resolveAtPosition(
        TextDocument $document,
        int $line,
        int $character,
    ): ?ResolvedSymbol;

    /**
     * Get members accessible on a type.
     * Used by: Completion (after -> or ::)
     *
     * @return list<ResolvedMember>
     */
    public function getAccessibleMembers(
        TextDocument $document,
        Type $type,
        Visibility $minVisibility,
        MemberFilter $filter = MemberFilter::Instance,
    ): array;

    /**
     * Check if a class can be instantiated with `new`.
     */
    public function isInstantiable(ClassName $className): bool;

    /**
     * Check if a class name is valid as a type hint.
     */
    public function isValidTypeHint(ClassName $className): bool;

    /**
     * Detect member access context at cursor position.
     * Used by: Completion
     */
    public function getMemberAccessContext(
        TextDocument $document,
        int $line,
        int $character,
    ): ?MemberAccessContext;

    /**
     * Get variables in scope at cursor position.
     * Used by: Completion
     *
     * @return list<ResolvedVariable>
     */
    public function getVariablesInScope(
        TextDocument $document,
        int $line,
        int $character,
    ): array;

    /**
     * Get call context at cursor position (for signature help).
     * Used by: SignatureHelp
     */
    public function getCallContext(
        TextDocument $document,
        int $line,
        int $character,
    ): ?CallContext;
}
