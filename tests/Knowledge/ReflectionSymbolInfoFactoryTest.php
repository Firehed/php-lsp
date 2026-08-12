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

    public function testBuildsClassInfoForALoadedClass(): void
    {
        $info = $this->build(\ArrayObject::class, NameKind::ClassLike);

        self::assertInstanceOf(ClassInfo::class, $info, 'a class-like must build ClassInfo');
        self::assertSame('ArrayObject', $info->name->fqn, 'the reflected class must be returned');
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
