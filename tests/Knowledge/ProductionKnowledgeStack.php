<?php

declare(strict_types=1);

namespace Firehed\PhpLsp\Tests\Knowledge;

use Firehed\PhpLsp\Index\ComposerAutoloadMap;
use Firehed\PhpLsp\Knowledge\KnowledgeStack;
use Firehed\PhpLsp\Knowledge\SymbolSinkInterface;
use Firehed\PhpLsp\Knowledge\SymbolSourceInterface;
use Firehed\PhpLsp\Tests\Parser\ProductionSyntaxSource;

/**
 * The test-facing wiring for the production symbol-knowledge stack. Mirrors what
 * {@see \Firehed\PhpLsp\Server::forProject} assembles, so a test that needs the
 * production read source and write sink builds them in one place.
 */
final readonly class ProductionKnowledgeStack
{
    public SymbolSourceInterface $source;
    public SymbolSinkInterface $sink;

    private function __construct(KnowledgeStack $stack)
    {
        $this->source = $stack->source;
        $this->sink = $stack->sink;
    }

    /**
     * Over the Composer maps generated under $projectRoot.
     */
    public static function forProjectRoot(string $projectRoot, ProductionSyntaxSource $syntax): self
    {
        return self::forMap(ComposerAutoloadMap::fromProjectRoot($projectRoot), $projectRoot, $syntax);
    }

    /**
     * Over a hand-built or empty map, for a project rooted at $projectRoot.
     */
    public static function forMap(ComposerAutoloadMap $map, string $projectRoot, ProductionSyntaxSource $syntax): self
    {
        return new self(KnowledgeStack::forProject(
            $map,
            $projectRoot . '/vendor',
            $syntax->source,
            $syntax->reader,
        ));
    }
}
