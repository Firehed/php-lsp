<?php

declare(strict_types=1);

namespace Firehed\PhpLsp\Tests\Index;

use Firehed\PhpLsp\Document\FileUri;
use Firehed\PhpLsp\Document\TextDocument;
use Firehed\PhpLsp\Domain\QualifiedName;
use Firehed\PhpLsp\Index\DeclarationScanner;
use Firehed\PhpLsp\Index\FileDeclarations;
use Firehed\PhpLsp\Parser\ParserService;
use Firehed\PhpLsp\Tests\LoadsFixturesTrait;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use ReflectionFunction;

#[CoversClass(DeclarationScanner::class)]
#[CoversClass(FileDeclarations::class)]
final class DeclarationScannerTest extends TestCase
{
    use LoadsFixturesTrait;

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
            [
                'Fixtures\Helpers\HELPER_LIMIT',
                'FIXTURE_HELPER_DEFINED',
                'Fixtures\Helpers\HELPER_DEFINED_QUALIFIED',
            ],
            self::fqns($declarations->constants),
            'a const declaration under a namespace is namespaced like a function',
        );
    }

    public function testDefineIgnoresTheNamespaceItIsWrittenIn(): void
    {
        $declarations = $this->scanFixture('AutoloadFiles/helpers.php');

        // The one non-obvious rule on this path: `const` under `namespace Foo` is
        // `Foo\X`, but `define('X')` in the same file is the global `X`.
        self::assertContains(
            'FIXTURE_HELPER_DEFINED',
            self::fqns($declarations->constants),
            'define() takes its whole name from the literal, not from the enclosing namespace',
        );
    }

    public function testAQualifiedDefineLiteralIsSplitIntoNamespaceAndShortName(): void
    {
        $declarations = $this->scanFixture('AutoloadFiles/helpers.php');

        $qualified = array_values(array_filter(
            $declarations->constants,
            static fn(QualifiedName $name): bool => $name->namespace !== '',
        ));

        self::assertSame(
            ['Fixtures\Helpers', 'Fixtures\Helpers'],
            array_map(static fn(QualifiedName $name): string => $name->namespace, $qualified),
            'a define() literal carrying a namespace is split like any other qualified name',
        );
    }

    public function testGlobalFunctionsAreReportedWithoutANamespace(): void
    {
        $declarations = $this->scanFixture('AutoloadFiles/globals.php');

        self::assertSame(
            ['fixtureGlobalHelper', 'fixtureConditionalHelper'],
            self::fqns($declarations->functions),
            'a function declared outside any namespace has an empty namespace path',
        );
    }

    public function testAConditionallyDeclaredFunctionIsReported(): void
    {
        $declarations = $this->scanFixture('AutoloadFiles/globals.php');

        // Nested inside an `if`, so a top-level-only walk would drop it.
        self::assertContains(
            'fixtureConditionalHelper',
            self::fqns($declarations->functions),
            'a declaration nested inside a conditional is still a declaration',
        );
    }

    public function testConstDeclarationAndLiteralDefineAreBothReported(): void
    {
        $declarations = $this->scanFixture('AutoloadFiles/globals.php');

        self::assertSame(
            [
                'FIXTURE_GLOBAL_LIMIT',
                'FIXTURE_GLOBAL_ALPHA',
                'FIXTURE_GLOBAL_BETA',
                'FIXTURE_DEFINED_LIMIT',
                'FIXTURE_UPPERCASE_DEFINED_LIMIT',
                'FIXTURE_NAMED_LIMIT',
                'FIXTURE_REORDERED_LIMIT',
            ],
            self::fqns($declarations->constants),
            'constant reach covers const declarations and literal-name define() alike (Plan 0002 §3b)',
        );
    }

    public function testDefineNamesItsConstantByNamedArgumentToo(): void
    {
        // Reading the first argument positionally would index the *value* of the
        // reordered call as if it were a constant name.
        $declarations = $this->scanFixture('AutoloadFiles/globals.php');

        self::assertSame(
            'constant_name',
            (new ReflectionFunction('define'))->getParameters()[0]->getName(),
            'the parameter the scanner matches by name is the one PHP declares',
        );
        self::assertContains(
            'FIXTURE_REORDERED_LIMIT',
            self::fqns($declarations->constants),
            'a named argument declares the constant it names regardless of its position',
        );
        self::assertNotContains(
            'FIXTURE_NOT_A_CONSTANT_NAME',
            self::fqns($declarations->constants),
            'the value of a define() call is not a constant name',
        );
    }

    public function testAFirstClassCallableDefineDeclaresNothing(): void
    {
        $declarations = $this->scanFixture('AutoloadFiles/globals.php');

        // `define(...)` parses to a placeholder rather than an argument: it makes a
        // Closure and declares nothing.
        self::assertNotContains(
            '',
            self::fqns($declarations->constants),
            'a call carrying no arguments contributes no constant',
        );
    }

    public function testEveryDeclaratorOfAConstStatementIsReported(): void
    {
        $declarations = $this->scanFixture('AutoloadFiles/globals.php');

        // `const A = 1, B = 2;` is one statement holding two declarations.
        self::assertContains(
            'FIXTURE_GLOBAL_BETA',
            self::fqns($declarations->constants),
            'the second declarator of a const statement is a declaration too',
        );
    }

    public function testDefineIsRecognisedRegardlessOfItsSpelling(): void
    {
        $declarations = $this->scanFixture('AutoloadFiles/globals.php');

        self::assertContains(
            'FIXTURE_UPPERCASE_DEFINED_LIMIT',
            self::fqns($declarations->constants),
            'define() is a function call, and function names are case-insensitive',
        );
    }

    public function testComputedDefineNameIsNotReported(): void
    {
        $declarations = $this->scanFixture('AutoloadFiles/globals.php');

        // Named rather than counted: this stays red for the right reason if the
        // scanner ever learns to fold `'A' . 'B'` into a name.
        self::assertNotContains(
            'FIXTURE_COMPUTED_LIMIT',
            self::fqns($declarations->constants),
            'a computed define() name exists only at runtime and contributes nothing (Plan 0002 §3b)',
        );
    }

    public function testAFileDeclaringNeitherYieldsNothing(): void
    {
        $declarations = $this->scanFixture('src/Domain/User.php');

        self::assertSame([], $declarations->functions, 'a class declares no free functions');
        self::assertSame([], $declarations->constants, 'a class constant is not a namespaced constant');
    }

    public function testAnEmptyAstYieldsNothing(): void
    {
        $declarations = $this->scanner->scan([]);

        self::assertSame([], $declarations->functions, 'an empty AST declares nothing, and is not an error');
        self::assertSame([], $declarations->constants, 'an empty AST declares nothing, and is not an error');
    }

    private function scanFixture(string $relativePath): FileDeclarations
    {
        $uri = FileUri::fromPath($this->fixturePath($relativePath));
        $content = $this->loadFixture($relativePath);

        $ast = $this->parser->parse(new TextDocument($uri, 'php', 0, $content));
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
