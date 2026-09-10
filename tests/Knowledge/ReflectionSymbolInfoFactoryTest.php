<?php

declare(strict_types=1);

namespace Firehed\PhpLsp\Tests\Knowledge;

use Firehed\PhpLsp\Domain\ClassInfo;
use Firehed\PhpLsp\Domain\ClassKind;
use Firehed\PhpLsp\Domain\FunctionInfo;
use Firehed\PhpLsp\Domain\NameKind;
use Firehed\PhpLsp\Domain\QualifiedName;
use Firehed\PhpLsp\Domain\SymbolInfo;
use Firehed\PhpLsp\Knowledge\ReflectionSymbolInfoFactory;
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
        $this->factory = new ReflectionSymbolInfoFactory();
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function loadedClassLikes(): iterable
    {
        yield 'class' => [\ArrayObject::class];
        yield 'interface' => [\Countable::class];
        yield 'enum' => [\Random\IntervalBoundary::class];
        // PHP has no built-in traits as of 8.5; if a future version adds one,
        // it should be added here.
        // yield 'trait' => [...];
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
        require_once dirname(__DIR__) . '/Domain/Fixtures/documented_function.php';

        self::assertNull(
            $this->build('testDocumentedFunction', NameKind::Function_),
            'a userland function loaded in the server process is not a built-in',
        );
    }

    public function testIgnoresClassLikesOnlyTheServerHasLoaded(): void
    {
        self::assertNull(
            $this->build(self::class, NameKind::ClassLike),
            'a userland class loaded in the server process is not a built-in',
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

    public function testBuiltinConstantIsResolved(): void
    {
        $info = $this->build('PHP_INT_MAX', NameKind::Constant);

        self::assertInstanceOf(
            \Firehed\PhpLsp\Domain\ConstantInfo::class,
            $info,
            'a built-in constant must resolve to ConstantInfo',
        );
    }

    public function testUserConstantIsNotResolved(): void
    {
        // Define a "user" constant that will be filtered out
        if (!defined('TEST_USER_CONSTANT')) {
            define('TEST_USER_CONSTANT', 'value');
        }

        self::assertNull(
            $this->build('TEST_USER_CONSTANT', NameKind::Constant),
            'a user-defined constant is not a built-in, so reflection must not resolve it',
        );
    }

    public function testClassInfoCarriesBasicMetadataForAPlainClass(): void
    {
        $info = $this->build(\stdClass::class, NameKind::ClassLike);

        self::assertInstanceOf(ClassInfo::class, $info);
        self::assertSame(\stdClass::class, $info->name->fqn);
        self::assertSame(ClassKind::Class_, $info->kind);
    }

    public function testClassInfoCapturesTheParentClass(): void
    {
        $info = $this->build(\RuntimeException::class, NameKind::ClassLike);

        self::assertInstanceOf(ClassInfo::class, $info);
        self::assertSame(\Exception::class, $info->parent?->fqn);
    }

    public function testClassInfoReportsInterfaceKindForABuiltinInterface(): void
    {
        $info = $this->build(\Iterator::class, NameKind::ClassLike);

        self::assertInstanceOf(ClassInfo::class, $info);
        self::assertSame(ClassKind::Interface_, $info->kind);
    }

    public function testClassInfoReportsEnumKindAndEnumCases(): void
    {
        $info = $this->build(\Random\IntervalBoundary::class, NameKind::ClassLike);

        self::assertInstanceOf(ClassInfo::class, $info);
        self::assertSame(ClassKind::Enum_, $info->kind);
        self::assertNotEmpty($info->enumCases, 'built-in enum cases must be extracted');
    }

    public function testClassInfoDetectsTheBuiltinAttributeClass(): void
    {
        $info = $this->build(\Attribute::class, NameKind::ClassLike);

        self::assertInstanceOf(ClassInfo::class, $info);
        self::assertTrue($info->isAttribute, 'the built-in Attribute class is itself an attribute');
    }

    public function testPlainClassIsNotMarkedAsAttribute(): void
    {
        $info = $this->build(\stdClass::class, NameKind::ClassLike);

        self::assertInstanceOf(ClassInfo::class, $info);
        self::assertFalse($info->isAttribute);
    }

    public function testClassInfoCarriesMethodsPropertiesAndInterfaces(): void
    {
        $info = $this->build(\ArrayObject::class, NameKind::ClassLike);

        self::assertInstanceOf(ClassInfo::class, $info);
        self::assertNotEmpty($info->methods, 'a built-in class must report its methods');
        self::assertNotEmpty($info->interfaces, 'ArrayObject implements several built-in interfaces');
    }

    public function testClassInfoCarriesConstants(): void
    {
        $info = $this->build(\ArrayObject::class, NameKind::ClassLike);

        self::assertInstanceOf(ClassInfo::class, $info);
        self::assertArrayHasKey('STD_PROP_LIST', $info->constants);
    }

    private function build(string $fqn, NameKind $kind): ?SymbolInfo
    {
        return $this->factory->fromReflection(QualifiedName::fromFullyQualified($fqn), $kind);
    }
}
