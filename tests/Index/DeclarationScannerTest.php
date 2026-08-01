<?php

declare(strict_types=1);

namespace Firehed\PhpLsp\Tests\Index;

use Firehed\PhpLsp\Document\TextDocument;
use Firehed\PhpLsp\Domain\QualifiedName;
use Firehed\PhpLsp\Index\DeclarationScanner;
use Firehed\PhpLsp\Index\FileDeclarations;
use Firehed\PhpLsp\Parser\ParserService;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(DeclarationScanner::class)]
#[CoversClass(FileDeclarations::class)]
final class DeclarationScannerTest extends TestCase
{
    private DeclarationScanner $scanner;
    private ParserService $parser;

    protected function setUp(): void
    {
        $this->scanner = new DeclarationScanner();
        $this->parser = new ParserService();
    }

    public function testNamespacedFunctionsAreReportedByFullyQualifiedName(): void
    {
        $declarations = $this->scanFixture('AutoloadFiles/helpers.php');

        self::assertSame(
            ['Fixtures\Helpers\helperFormat', 'Fixtures\Helpers\helperNormalize'],
            self::fqns($declarations->functions),
            'a function declared under a namespace is reachable only by its qualified name',
        );
    }

    public function testNamespacedConstantsAreReportedByFullyQualifiedName(): void
    {
        $declarations = $this->scanFixture('AutoloadFiles/helpers.php');

        self::assertSame(
            ['Fixtures\Helpers\HELPER_LIMIT'],
            self::fqns($declarations->constants),
            'a const declaration under a namespace is namespaced like a function',
        );
    }

    public function testGlobalFunctionsAreReportedWithoutANamespace(): void
    {
        $declarations = $this->scanFixture('AutoloadFiles/globals.php');

        self::assertSame(
            ['fixtureGlobalHelper'],
            self::fqns($declarations->functions),
            'a function declared outside any namespace has an empty namespace path',
        );
    }

    public function testConstDeclarationAndLiteralDefineAreBothReported(): void
    {
        $declarations = $this->scanFixture('AutoloadFiles/globals.php');

        self::assertSame(
            ['FIXTURE_GLOBAL_LIMIT', 'FIXTURE_DEFINED_LIMIT'],
            self::fqns($declarations->constants),
            'constant reach covers const declarations and literal-name define() alike (Plan 0002 §3b)',
        );
    }

    public function testComputedDefineNameIsNotReported(): void
    {
        $declarations = $this->scanFixture('AutoloadFiles/globals.php');

        self::assertNotContains(
            'FIXTURE_COMPUTED_LIMIT',
            self::fqns($declarations->constants),
            'a computed define() name exists only at runtime and is out of scope (Plan 0002 §3b)',
        );
    }

    public function testAFileDeclaringNeitherYieldsNothing(): void
    {
        // A class-like file is the common case in an autoload.files set that also
        // pulls in a bootstrap; it contributes no function or constant names.
        $declarations = $this->scanFixture('src/Domain/User.php');

        self::assertSame([], $declarations->functions, 'a class declares no free functions');
        self::assertSame([], $declarations->constants, 'a class constant is not a namespaced constant');
    }

    public function testAnUnparseableFileYieldsNothing(): void
    {
        $declarations = $this->scanner->scan([]);

        self::assertSame([], $declarations->functions, 'no AST means nothing declared, not an error');
        self::assertSame([], $declarations->constants, 'no AST means nothing declared, not an error');
    }

    private function scanFixture(string $relativePath): FileDeclarations
    {
        $path = __DIR__ . '/../Fixtures/' . $relativePath;
        $content = file_get_contents($path);
        self::assertNotFalse($content, "fixture should be readable: {$relativePath}");

        $ast = $this->parser->parse(new TextDocument('file://' . $path, 'php', 0, $content));
        self::assertNotNull($ast, "fixture should parse: {$relativePath}");

        return $this->scanner->scan($ast);
    }

    /**
     * @param list<QualifiedName> $names
     * @return list<string>
     */
    private static function fqns(array $names): array
    {
        return array_map(static fn(QualifiedName $name): string => $name->fullyQualifiedName(), $names);
    }
}
