<?php

declare(strict_types=1);

namespace Firehed\PhpLsp\Tests\Domain;

use Firehed\PhpLsp\Domain\ResolvedSymbol;

/**
 * The four cases every Info implementing {@see \Firehed\PhpLsp\Domain\ResolvedSymbol}
 * shares: file/line -> Location round-trip, null file, docblock -> description,
 * null docblock. Using classes supply {@see self::makeSubject()} once instead of
 * writing the same four tests per class.
 */
trait HasSymbolLocationTestTrait
{
    abstract protected function makeSubject(
        ?string $file = null,
        ?int $line = null,
        ?string $docblock = null,
    ): ResolvedSymbol;

    public function testGetDefinitionLocation(): void
    {
        $location = $this->makeSubject('/path/to/file.php', 10)->getDefinitionLocation();

        self::assertNotNull($location);
        self::assertSame('file:///path/to/file.php', $location->uri);
        self::assertSame(9, $location->startLine);
    }

    public function testGetDefinitionLocationNullWhenFileNull(): void
    {
        self::assertNull($this->makeSubject(null, 10)->getDefinitionLocation());
    }

    public function testGetDocumentation(): void
    {
        $subject = $this->makeSubject(docblock: "/**\n * Test description\n */");

        self::assertSame('Test description', $subject->getDocumentation());
    }

    public function testGetDocumentationNullWhenNoDocblock(): void
    {
        self::assertNull($this->makeSubject(docblock: null)->getDocumentation());
    }
}
