<?php

declare(strict_types=1);

namespace Firehed\PhpLsp\Resolution;

/**
 * Common tests for classes using the ResolvesFromInfo trait.
 *
 * Using classes must implement createSubjectWithLocation() and
 * createSubjectWithDocblock() to provide test subjects.
 */
trait ResolvesFromInfoTestTrait
{
    abstract protected function createSubjectWithLocation(?string $file, ?int $line): ResolvedSymbol;

    abstract protected function createSubjectWithDocblock(?string $docblock): ResolvedSymbol;

    public function testGetDefinitionLocation(): void
    {
        $resolved = $this->createSubjectWithLocation('/path/to/file.php', 10);

        $location = $resolved->getDefinitionLocation();

        self::assertNotNull($location);
        self::assertSame('file:///path/to/file.php', $location->uri);
        self::assertSame(9, $location->startLine);
    }

    public function testGetDefinitionLocationReturnsNullWhenFileIsNull(): void
    {
        $resolved = $this->createSubjectWithLocation(null, 10);

        self::assertNull($resolved->getDefinitionLocation());
    }

    public function testGetDocumentation(): void
    {
        $resolved = $this->createSubjectWithDocblock("/**\n * Test description\n */");

        self::assertSame('Test description', $resolved->getDocumentation());
    }

    public function testGetDocumentationReturnsNullWhenNoDocblock(): void
    {
        $resolved = $this->createSubjectWithDocblock(null);

        self::assertNull($resolved->getDocumentation());
    }
}
