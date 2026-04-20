<?php

declare(strict_types=1);

namespace Firehed\PhpLsp\Tests\Utility;

use Firehed\PhpLsp\Utility\AccessContext;
use Firehed\PhpLsp\Utility\VisibilityFilter;
use PhpParser\ParserFactory;
use PhpParser\Node\Stmt;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;
use ReflectionProperty;

#[CoversClass(VisibilityFilter::class)]
class VisibilityFilterTest extends TestCase
{
    /**
     * @return array<string, array{AccessContext, int, bool}>
     */
    public static function methodVisibilityProvider(): array
    {
        return [
            'SameClass sees public' => [AccessContext::SameClass, Stmt\Class_::MODIFIER_PUBLIC, true],
            'SameClass sees protected' => [AccessContext::SameClass, Stmt\Class_::MODIFIER_PROTECTED, true],
            'SameClass sees private' => [AccessContext::SameClass, Stmt\Class_::MODIFIER_PRIVATE, true],
            'Subclass sees public' => [AccessContext::Subclass, Stmt\Class_::MODIFIER_PUBLIC, true],
            'Subclass sees protected' => [AccessContext::Subclass, Stmt\Class_::MODIFIER_PROTECTED, true],
            'Subclass does not see private' => [AccessContext::Subclass, Stmt\Class_::MODIFIER_PRIVATE, false],
            'External sees public' => [AccessContext::External, Stmt\Class_::MODIFIER_PUBLIC, true],
            'External does not see protected' => [AccessContext::External, Stmt\Class_::MODIFIER_PROTECTED, false],
            'External does not see private' => [AccessContext::External, Stmt\Class_::MODIFIER_PRIVATE, false],
        ];
    }

    #[DataProvider('methodVisibilityProvider')]
    public function testIsMethodAccessible(AccessContext $context, int $modifier, bool $expected): void
    {
        $code = <<<PHP
<?php
class MyClass {
    {$this->modifierKeyword($modifier)} function myMethod() {}
}
PHP;
        $ast = $this->parse($code);
        $classNode = $ast[0];
        assert($classNode instanceof Stmt\Class_);
        $method = $classNode->stmts[0];
        assert($method instanceof Stmt\ClassMethod);

        self::assertSame($expected, VisibilityFilter::isMethodAccessible($method, $context));
    }

    #[DataProvider('methodVisibilityProvider')]
    public function testIsPropertyAccessible(AccessContext $context, int $modifier, bool $expected): void
    {
        $code = <<<PHP
<?php
class MyClass {
    {$this->modifierKeyword($modifier)} \$myProperty;
}
PHP;
        $ast = $this->parse($code);
        $classNode = $ast[0];
        assert($classNode instanceof Stmt\Class_);
        $property = $classNode->stmts[0];
        assert($property instanceof Stmt\Property);

        self::assertSame($expected, VisibilityFilter::isPropertyAccessible($property, $context));
    }

    public function testIsReflectionMethodAccessibleSameClass(): void
    {
        $reflection = new ReflectionMethod(TestFixtureClass::class, 'publicMethod');
        self::assertTrue(VisibilityFilter::isReflectionMethodAccessible($reflection, AccessContext::SameClass));

        $reflection = new ReflectionMethod(TestFixtureClass::class, 'protectedMethod');
        self::assertTrue(VisibilityFilter::isReflectionMethodAccessible($reflection, AccessContext::SameClass));

        $reflection = new ReflectionMethod(TestFixtureClass::class, 'privateMethod');
        self::assertTrue(VisibilityFilter::isReflectionMethodAccessible($reflection, AccessContext::SameClass));
    }

    public function testIsReflectionMethodAccessibleSubclass(): void
    {
        $reflection = new ReflectionMethod(TestFixtureClass::class, 'publicMethod');
        self::assertTrue(VisibilityFilter::isReflectionMethodAccessible($reflection, AccessContext::Subclass));

        $reflection = new ReflectionMethod(TestFixtureClass::class, 'protectedMethod');
        self::assertTrue(VisibilityFilter::isReflectionMethodAccessible($reflection, AccessContext::Subclass));

        $reflection = new ReflectionMethod(TestFixtureClass::class, 'privateMethod');
        self::assertFalse(VisibilityFilter::isReflectionMethodAccessible($reflection, AccessContext::Subclass));
    }

    public function testIsReflectionMethodAccessibleExternal(): void
    {
        $reflection = new ReflectionMethod(TestFixtureClass::class, 'publicMethod');
        self::assertTrue(VisibilityFilter::isReflectionMethodAccessible($reflection, AccessContext::External));

        $reflection = new ReflectionMethod(TestFixtureClass::class, 'protectedMethod');
        self::assertFalse(VisibilityFilter::isReflectionMethodAccessible($reflection, AccessContext::External));

        $reflection = new ReflectionMethod(TestFixtureClass::class, 'privateMethod');
        self::assertFalse(VisibilityFilter::isReflectionMethodAccessible($reflection, AccessContext::External));
    }

    public function testIsReflectionPropertyAccessibleSameClass(): void
    {
        $reflection = new ReflectionProperty(TestFixtureClass::class, 'publicProperty');
        self::assertTrue(VisibilityFilter::isReflectionPropertyAccessible($reflection, AccessContext::SameClass));

        $reflection = new ReflectionProperty(TestFixtureClass::class, 'protectedProperty');
        self::assertTrue(VisibilityFilter::isReflectionPropertyAccessible($reflection, AccessContext::SameClass));

        $reflection = new ReflectionProperty(TestFixtureClass::class, 'privateProperty');
        self::assertTrue(VisibilityFilter::isReflectionPropertyAccessible($reflection, AccessContext::SameClass));
    }

    public function testIsReflectionPropertyAccessibleSubclass(): void
    {
        $reflection = new ReflectionProperty(TestFixtureClass::class, 'publicProperty');
        self::assertTrue(VisibilityFilter::isReflectionPropertyAccessible($reflection, AccessContext::Subclass));

        $reflection = new ReflectionProperty(TestFixtureClass::class, 'protectedProperty');
        self::assertTrue(VisibilityFilter::isReflectionPropertyAccessible($reflection, AccessContext::Subclass));

        $reflection = new ReflectionProperty(TestFixtureClass::class, 'privateProperty');
        self::assertFalse(VisibilityFilter::isReflectionPropertyAccessible($reflection, AccessContext::Subclass));
    }

    public function testIsReflectionPropertyAccessibleExternal(): void
    {
        $reflection = new ReflectionProperty(TestFixtureClass::class, 'publicProperty');
        self::assertTrue(VisibilityFilter::isReflectionPropertyAccessible($reflection, AccessContext::External));

        $reflection = new ReflectionProperty(TestFixtureClass::class, 'protectedProperty');
        self::assertFalse(VisibilityFilter::isReflectionPropertyAccessible($reflection, AccessContext::External));

        $reflection = new ReflectionProperty(TestFixtureClass::class, 'privateProperty');
        self::assertFalse(VisibilityFilter::isReflectionPropertyAccessible($reflection, AccessContext::External));
    }

    private function modifierKeyword(int $modifier): string
    {
        return match ($modifier) {
            Stmt\Class_::MODIFIER_PUBLIC => 'public',
            Stmt\Class_::MODIFIER_PROTECTED => 'protected',
            Stmt\Class_::MODIFIER_PRIVATE => 'private',
            default => throw new \InvalidArgumentException("Unexpected modifier: $modifier"),
        };
    }

    /**
     * @return array<Stmt>
     */
    private function parse(string $code): array
    {
        $parserFactory = new ParserFactory();
        $parser = $parserFactory->createForNewestSupportedVersion();
        $result = $parser->parse($code);
        assert($result !== null);
        return $result;
    }
}
