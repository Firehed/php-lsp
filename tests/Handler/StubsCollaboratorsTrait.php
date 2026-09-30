<?php

declare(strict_types=1);

namespace Firehed\PhpLsp\Tests\Handler;

use Firehed\PhpLsp\Document\DocumentSourceInterface;
use Firehed\PhpLsp\Document\TextDocument;
use Firehed\PhpLsp\Domain\ResolvedSymbolInterface;
use Firehed\PhpLsp\Resolution\CodeResolverInterface;

/**
 * Stubs of the interface collaborators every handler unit test needs. Handler-
 * specific collaborators (a capability provider, a completion source, a
 * CallContext-returning resolver) stay in the test that uses them; only the
 * shapes shared across handlers live here.
 */
trait StubsCollaboratorsTrait
{
    private function documentsReturning(TextDocument $document): DocumentSourceInterface
    {
        $stub = self::createStub(DocumentSourceInterface::class);
        $stub->method('read')->willReturn($document);
        return $stub;
    }

    private function resolverReturning(?ResolvedSymbolInterface $symbol): CodeResolverInterface
    {
        $stub = self::createStub(CodeResolverInterface::class);
        $stub->method('resolveAtPosition')->willReturn($symbol);
        return $stub;
    }
}
