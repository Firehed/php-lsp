<?php

declare(strict_types=1);

namespace Firehed\PhpLsp\Tests\Index;

use Firehed\PhpLsp\Document\FileUri;
use Firehed\PhpLsp\Document\TextDocument;
use Firehed\PhpLsp\Knowledge\Declaration;
use Firehed\PhpLsp\Knowledge\DeclarationScanner;
use Firehed\PhpLsp\Knowledge\FileDeclarations;
use Firehed\PhpLsp\Parser\ParserService;
use Firehed\PhpLsp\Tests\LoadsFixturesTrait;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use PhpParser\Node;
use PhpParser\Node\Expr;
use PhpParser\Node\Stmt;
use ReflectionFunction;

#[CoversClass(Declaration::class)]
#[CoversClass(DeclarationScanner::class)]
#[CoversClass(FileDeclarations::class)]
final class DeclarationScannerTest extends TestCase
{
    use LoadsFixturesTrait;

    /**
     * Every constant AutoloadFiles/globals.php declares, in declaration order.
     * A first-class-callable `define(...)` yields no name to probe for, so the test
     * for it asserts against this whole list instead.
     */
    private const GLOBAL_CONSTANTS = [
        'FIXTURE_GLOBAL_LIMIT',
        'FIXTURE_GLOBAL_ALPHA',
        'FIXTURE_GLOBAL_BETA',
        'FIXTURE_DEFINED_LIMIT',
        'FIXTURE_UPPERCASE_DEFINED_LIMIT',
        'FIXTURE_NAMED_LIMIT',
        'FIXTURE_REORDERED_LIMIT',
        'FIXTURE_BODY_LIMIT',
    ];

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
            static fn(Declaration $declaration): bool => $declaration->name->namespace !== '',
        ));

        self::assertSame(
            ['Fixtures\Helpers', 'Fixtures\Helpers'],
            array_map(static fn(Declaration $declaration): string => $declaration->name->namespace, $qualified),
            'a define() literal carrying a namespace is split like any other qualified name',
        );
    }

    public function testGlobalFunctionsAreReportedWithoutANamespace(): void
    {
        $declarations = $this->scanFixture('AutoloadFiles/globals.php');

        self::assertSame(
            ['fixtureGlobalHelper', 'fixtureConditionalHelper', 'fixtureBootstrap', 'fixtureNestedHelper'],
            self::fqns($declarations->functions),
            'a function declared outside any namespace has an empty namespace path',
        );
    }

    public function testDeclarationsInsideAFunctionBodyAreReported(): void
    {
        $declarations = $this->scanFixture('AutoloadFiles/globals.php');

        // The rule is lexical, not executional. Nesting is legal PHP that declares
        // a real symbol once the body runs, and whether it runs is a runtime
        // question a static parse cannot settle — so the name resolves.
        self::assertContains(
            'fixtureNestedHelper',
            self::fqns($declarations->functions),
            'a function declared inside a function body is still lexically declared',
        );
        self::assertContains(
            'FIXTURE_BODY_LIMIT',
            self::fqns($declarations->constants),
            'a define() inside a function body is reported on the same lexical rule',
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
            self::GLOBAL_CONSTANTS,
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
        // Closure and declares nothing. Asserted against the whole list, so a
        // placeholder that contributed any name at all would show up.
        self::assertSame(
            self::GLOBAL_CONSTANTS,
            self::fqns($declarations->constants),
            'a call whose arguments are a placeholder contributes no constant',
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

    public function testNamespacedClassLikesAreReportedByFullyQualifiedName(): void
    {
        $declarations = $this->scanFixture('AutoloadFiles/helpers.php');

        // Interfaces, traits and enums are class-likes too: Composer addresses none
        // of them by name when the file is reached only through `autoload.files`, so
        // narrowing the scan to `class` and `interface` would lose two of the four.
        self::assertSame(
            [
                'Fixtures\Helpers\HelperContract',
                'Fixtures\Helpers\HelperFallback',
                'Fixtures\Helpers\HelperMode',
                'Fixtures\Helpers\HelperRegistry',
            ],
            self::fqns($declarations->classLikes),
            'every class-like flavour in a files entry is indexed, not just classes',
        );
    }

    public function testGlobalClassLikesAreReportedWithoutANamespace(): void
    {
        $declarations = $this->scanFixture('AutoloadFiles/globals.php');

        self::assertSame(
            ['FixtureGlobalRegistry'],
            self::fqns($declarations->classLikes),
            'a class declared outside any namespace has an empty namespace path',
        );
    }

    public function testAnAnonymousClassIsNotADeclaration(): void
    {
        $declarations = $this->scanFixture('AutoloadFiles/globals.php');

        // Asserted through the whole list: an anonymous class has no name to probe
        // for, and reporting one would mean indexing an unnameable symbol.
        self::assertSame(
            ['FixtureGlobalRegistry'],
            self::fqns($declarations->classLikes),
            'an anonymous class cannot be looked up by name, so it is not indexed',
        );
    }

    public function testAClassFileDeclaresOnlyItsClassLike(): void
    {
        $declarations = $this->scanFixture('src/Domain/User.php');

        self::assertSame(
            ['Fixtures\Domain\User'],
            self::fqns($declarations->classLikes),
            'a PSR-4 class file declares exactly the class it is named for',
        );
        self::assertSame([], $declarations->functions, 'a class declares no free functions');
        self::assertSame([], $declarations->constants, 'a class constant is not a namespaced constant');
    }

    public function testEachDeclarationCarriesTheNodeThatDeclaresIt(): void
    {
        $declarations = $this->scanFixture('AutoloadFiles/globals.php');

        // Pairing, not merely presence: a backend builds its metadata from this
        // node, so a name paired with a neighbouring declaration would describe the
        // wrong symbol entirely.
        foreach ($declarations->functions as $declaration) {
            $node = $declaration->node;
            self::assertInstanceOf(Stmt\Function_::class, $node, 'a function is declared by a function node');
            self::assertSame(
                $declaration->name->shortName,
                $node->name->toString(),
                'a declaration carries the node declaring its own name',
            );
        }

        self::assertInstanceOf(
            Stmt\Class_::class,
            $declarations->classLikes[0]->node,
            'a class is declared by a class node',
        );

        // `const A = 1, B = 2;` is one statement holding two declarators, so the
        // statement cannot be the node: both names would carry the first's value.
        $beta = $declarations->constants[2];
        self::assertSame('FIXTURE_GLOBAL_BETA', $beta->name->shortName, 'the third constant is the second declarator');
        self::assertInstanceOf(Node\Const_::class, $beta->node, 'the declarator is the node, not its statement');
        self::assertSame(
            'FIXTURE_GLOBAL_BETA',
            $beta->node->name->toString(),
            'the node is this name\'s own declarator, not the first one its statement holds',
        );

        self::assertInstanceOf(
            Expr\FuncCall::class,
            $declarations->constants[3]->node,
            'a define() constant is declared by the call that names it',
        );
    }

    public function testAnEmptyAstYieldsNothing(): void
    {
        $declarations = $this->scanner->scan([]);

        self::assertSame([], $declarations->classLikes, 'an empty AST declares nothing, and is not an error');
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
     * @param list<Declaration<Node>> $declarations
     * @return list<string>
     */
    private static function fqns(array $declarations): array
    {
        return array_map(
            static fn(Declaration $declaration): string => $declaration->name->fullyQualifiedName(),
            $declarations,
        );
    }
}
