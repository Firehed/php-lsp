<?php

declare(strict_types=1);

namespace Firehed\PhpLsp\Tests\Knowledge;

use Firehed\PhpLsp\Domain\ClassInfo;
use Firehed\PhpLsp\Domain\FunctionInfo;
use Firehed\PhpLsp\Domain\NameKind;
use Firehed\PhpLsp\Domain\QualifiedName;
use Firehed\PhpLsp\Domain\SymbolInfo;
use Firehed\PhpLsp\Knowledge\ReflectionSymbolInfoFactory;
use Firehed\PhpLsp\Repository\DefaultClassInfoFactory;
use Firehed\PhpLsp\Resolution\ResolvesFromInfo;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * The reflection counterpart of {@see DeclarationSymbolInfoFactoryTest}
 * (Plan 0002 §5.6).
 */
final class ReflectionSymbolInfoFactoryTest extends TestCase
{
    private ReflectionSymbolInfoFactory $factory;

    protected function setUp(): void
    {
        $this->factory = new ReflectionSymbolInfoFactory(new DefaultClassInfoFactory());
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function loadedClassLikes(): iterable
    {
        yield 'class' => [\ArrayObject::class];
        yield 'interface' => [\Countable::class];
        yield 'enum' => [\Random\IntervalBoundary::class];
        // PHP declares no internal trait, so the only probe for the fourth flavour is
        // one the server process loaded — which this branch answers for until SC.14
        // filters it to internal, after which the branch is dead and goes with it.
        yield 'trait' => [ResolvesFromInfo::class];
    }

    #[DataProvider('loadedClassLikes')]
    public function testBuildsClassInfoForEveryClassLikeFlavour(string $fqn): void
    {
        $info = $this->build($fqn, NameKind::ClassLike);

        self::assertInstanceOf(ClassInfo::class, $info, 'a class-like must build ClassInfo');
        self::assertSame($fqn, $info->name->fqn, 'the reflected class-like must be returned');
    }

    public function testBuildsFunctionInfoForAnInternalFunction(): void
    {
        $info = $this->build('str_contains', NameKind::Function_);

        self::assertInstanceOf(FunctionInfo::class, $info, 'a function must build FunctionInfo');
        self::assertCount(2, $info->parameters, 'the reflected signature must be carried, not just the name');
    }

    public function testIgnoresFunctionsOnlyTheServerHasLoaded(): void
    {
        // Enumeration is filtered to internal, so a broader lookup would resolve a
        // name completion never offers (RFC 1 §4.2).
        require_once dirname(__DIR__) . '/Domain/Fixtures/documented_function.php';

        self::assertNull(
            $this->build('testDocumentedFunction', NameKind::Function_),
            'a userland function loaded in the server process is not a built-in',
        );
    }

    /**
     * @return iterable<string, array{string, NameKind}>
     */
    public static function absentNames(): iterable
    {
        yield 'class-like' => ['No\Such\Builtin', NameKind::ClassLike];
        yield 'function' => ['no_such_builtin', NameKind::Function_];
        // The kind selects which reflection is consulted, so a name that exists in
        // one of PHP's symbol namespaces is not answered for another.
        yield 'a function asked for as a class' => ['str_contains', NameKind::ClassLike];
        yield 'a class asked for as a function' => [\ArrayObject::class, NameKind::Function_];
    }

    #[DataProvider('absentNames')]
    public function testReturnsNullWhenReflectionCannotDescribeTheName(string $fqn, NameKind $kind): void
    {
        self::assertNull(
            $this->build($fqn, $kind),
            'a name reflection cannot load for this kind is absent (RFC 1 §5.3)',
        );
    }

    public function testConstantsAreNotYetBuilt(): void
    {
        self::assertNull(
            $this->build('PHP_INT_MAX', NameKind::Constant),
            'global-constant metadata arrives with S3.8b',
        );
    }

    private function build(string $fqn, NameKind $kind): ?SymbolInfo
    {
        return $this->factory->fromReflection(QualifiedName::fromFullyQualified($fqn), $kind);
    }
}
