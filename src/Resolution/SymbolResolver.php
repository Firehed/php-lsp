<?php

declare(strict_types=1);

namespace Firehed\PhpLsp\Resolution;

use Firehed\PhpLsp\Document\TextDocument;
use Firehed\PhpLsp\Parser\NodeLocator\NodeLocatorInterface;
use Firehed\PhpLsp\Parser\SyntaxSource\SyntaxSourceInterface;
use Firehed\PhpLsp\Repository\MemberResolverInterface;

/**
 * Centralizes symbol resolution for LSP handlers.
 *
 * This class provides a single entry point for resolving symbols at cursor
 * positions, eliminating the M×N problem where M handlers each independently
 * implement resolution logic for N node types.
 *
 * FUTURE: Workspace queries (requires index)
 * - findReferences(SymbolIdentity $symbol, ?Scope $scope = null): array<Location>
 * - findImplementations(ClasslikeName $interface): array<ClassInfo>
 * - findSubtypes(ClasslikeName $class): array<ClassInfo>
 * - findSupertypes(ClasslikeName $class): array<ClassInfo>
 *
 * FUTURE: Call hierarchy
 * - getIncomingCalls(ResolvedCallableInterface $callable): array<CallHierarchyItem>
 * - getOutgoingCalls(ResolvedCallableInterface $callable): array<CallHierarchyItem>
 *
 * FUTURE: Batch operations (for SemanticTokens)
 * - resolveAllSymbols(Document $document): array<ResolvedToken>
 */
final class SymbolResolver implements CodeResolverInterface
{
    private readonly MemberAccessDetector $memberAccessDetector;

    public function __construct(
        private readonly SyntaxSourceInterface $parser,
        private readonly NodeLocatorInterface $locator,
        private readonly MemberResolverInterface $memberResolver,
        private readonly ExpressionResolverInterface $expressions,
    ) {
        $this->memberAccessDetector = new MemberAccessDetector(
            $memberResolver,
            $locator,
            $expressions,
        );
    }

    /**
     * Get member access context at position.
     * Used by: Completion (after -> or ::)
     */
    public function getMemberAccessContext(
        TextDocument $document,
        int $line,
        int $character,
    ): ?MemberAccessContext {
        return $this->memberAccessDetector->detect($this->parser->parse($document), $line, $character);
    }
}
