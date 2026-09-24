<?php

declare(strict_types=1);

namespace Firehed\PhpLsp\Tests;

use Firehed\PhpLsp\Index\ComposerAutoloadMap;
use Firehed\PhpLsp\Knowledge\KnowledgeStack;
use Firehed\PhpLsp\Tests\Parser\ProductionSyntaxSource;

/**
 * Builds the production symbol-knowledge stack for tests, mirroring what
 * {@see \Firehed\PhpLsp\Server::forProject} assembles, so the wiring lives in
 * one place under tests/.
 */
trait BuildsKnowledgeStackTrait
{
    /**
     * Over a hand-built or empty map.
     */
    private function knowledgeStackForMap(ComposerAutoloadMap $map, ProductionSyntaxSource $syntax): KnowledgeStack
    {
        return KnowledgeStack::forProject($map, $syntax->source, $syntax->reader);
    }

    /**
     * Over the Composer maps generated under $projectRoot.
     */
    private function knowledgeStackForProjectRoot(string $projectRoot, ProductionSyntaxSource $syntax): KnowledgeStack
    {
        return $this->knowledgeStackForMap(ComposerAutoloadMap::fromProjectRoot($projectRoot), $syntax);
    }
}
