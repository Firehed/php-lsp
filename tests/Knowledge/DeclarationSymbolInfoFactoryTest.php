<?php

declare(strict_types=1);

namespace Firehed\PhpLsp\Tests\Knowledge;

use Firehed\PhpLsp\Domain\ClassInfo;
use Firehed\PhpLsp\Domain\FunctionInfo;
use Firehed\PhpLsp\Domain\NameKind;
use Firehed\PhpLsp\Domain\QualifiedName;
use Firehed\PhpLsp\Domain\SymbolInfo;
use Firehed\PhpLsp\Index\DeclarationScanner;
use Firehed\PhpLsp\Index\FileDeclarations;
use Firehed\PhpLsp\Knowledge\DeclarationSymbolInfoFactory;
use Firehed\PhpLsp\Parser\ParserService;
use Firehed\PhpLsp\Repository\DefaultClassInfoFactory;
use Firehed\PhpLsp\Tests\LoadsFixturesTrait;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * The single place a {@see NameKind} selects which declarations to search and which
 * factory builds the metadata (Plan 0002 §5.6). Confining that dispatch here is what
 * lets {@see \Firehed\PhpLsp\Knowledge\SymbolBackend} carry one lookup rather than
 * one per kind, so a new kind is a case here and not a method on every backend.
 */
final class DeclarationSymbolInfoFactoryTest extends TestCase
{
    use LoadsFixturesTrait;

    /**
     * Declares a class, an interface, a trait, an enum, two functions, and both
     * `const` and `define()` constants — every kind this factory dispatches on, in
     * one file, so a kind reading the wrong declaration list is visible.
     */
    private const string FIXTURE = 'AutoloadFiles/helpers.php';

    private DeclarationSymbolInfoFactory $factory;
    private FileDeclarations $declarations;
    private string $path;

    protected function setUp(): void
    {
        $this->factory = new DeclarationSymbolInfoFactory(new DefaultClassInfoFactory());
        $this->path = $this->fixturePath(self::FIXTURE);

        $ast = (new ParserService())->parseFile($this->path);
        self::assertNotNull($ast, 'the fixture must parse so declarations can be scanned');
        $this->declarations = (new DeclarationScanner())->scan($ast);
    }

    public function testBuildsClassInfoForAClassLikeDeclaration(): void
    {
        $info = $this->build('Fixtures\Helpers\HelperRegistry', NameKind::ClassLike);

        self::assertInstanceOf(ClassInfo::class, $info, 'a class-like must build ClassInfo, not another kind\'s type');
        self::assertSame('Fixtures\Helpers\HelperRegistry', $info->name->fqn, 'the located declaration must be built');
    }

    public function testBuildsFunctionInfoForAFunctionDeclaration(): void
    {
        $info = $this->build('Fixtures\Helpers\helperFormat', NameKind::Function_);

        self::assertInstanceOf(FunctionInfo::class, $info, 'a function must build FunctionInfo');
        self::assertCount(1, $info->parameters, 'the parsed signature must be carried, not just the name');
        self::assertSame($this->path, $info->file, 'the declaring file must be recorded from the path given');
    }

    public function testReturnsNullWhenTheFileDeclaresNoSuchName(): void
    {
        self::assertNull(
            $this->build('Fixtures\Helpers\notDeclaredHere', NameKind::Function_),
            'a name the declarations do not carry is absent (RFC 1 §5.3)',
        );
    }

    /**
     * The kind selects the declaration list, so a name declared only as one kind is
     * not answered when asked for as another. Reading a single merged list — or the
     * wrong list — would resolve these, which is the collision PHP's three
     * independent symbol namespaces make possible.
     *
     * @return iterable<string, array{string, NameKind}>
     */
    public static function crossKindQueries(): iterable
    {
        yield 'a class asked for as a function' => ['Fixtures\Helpers\HelperRegistry', NameKind::Function_];
        yield 'a function asked for as a class' => ['Fixtures\Helpers\helperFormat', NameKind::ClassLike];
        yield 'a constant asked for as a class' => ['Fixtures\Helpers\HELPER_LIMIT', NameKind::ClassLike];
    }

    #[DataProvider('crossKindQueries')]
    public function testAKindOnlyAnswersItsOwnDeclarations(string $fqn, NameKind $kind): void
    {
        self::assertNull(
            $this->build($fqn, $kind),
            'the kind must select the declaration list, so one namespace cannot answer for another',
        );
    }

    /**
     * @return iterable<string, array{string, NameKind}>
     */
    public static function caseInsensitiveQueries(): iterable
    {
        yield 'class-like' => ['FIXTURES\HELPERS\HELPERREGISTRY', NameKind::ClassLike];
        yield 'function' => ['FIXTURES\HELPERS\HELPERFORMAT', NameKind::Function_];
    }

    #[DataProvider('caseInsensitiveQueries')]
    public function testMatchingFollowsTheKindsCaseRule(string $fqn, NameKind $kind): void
    {
        self::assertNotNull(
            $this->build($fqn, $kind),
            'PHP matches class and function names case-insensitively, which NameKind::normalize owns',
        );
    }

    public function testConstantsAreNotYetBuilt(): void
    {
        // The fixture does declare this constant, so the null is the missing
        // global-constant info type, not a missing declaration. S3.8b adds it,
        // together with the Domain\ConstantName naming clash it forces.
        self::assertNotSame(
            [],
            $this->declarations->constants,
            'the fixture must declare constants, or this test would pass vacuously',
        );
        self::assertNull(
            $this->build('Fixtures\Helpers\HELPER_LIMIT', NameKind::Constant),
            'global-constant metadata arrives with S3.8b',
        );
    }

    private function build(string $fqn, NameKind $kind): ?SymbolInfo
    {
        return $this->factory->fromDeclarations(
            $this->declarations,
            QualifiedName::fromFullyQualified($fqn),
            $kind,
            $this->path,
        );
    }
}
