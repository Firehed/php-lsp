<?php

declare(strict_types=1);

namespace Firehed\PhpLsp\Tests\Repository;

use Firehed\PhpLsp\Domain\ClassKind;
use Firehed\PhpLsp\Domain\Visibility;
use Firehed\PhpLsp\Repository\DefaultClassInfoFactory;
use Firehed\PhpLsp\Tests\LoadsFixturesTrait;
use PhpParser\Node\Stmt;
use PhpParser\ParserFactory;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

#[CoversClass(DefaultClassInfoFactory::class)]
final class DefaultClassInfoFactoryTest extends TestCase
{
    use LoadsFixturesTrait;

    private DefaultClassInfoFactory $factory;

    protected function setUp(): void
    {
        $this->factory = new DefaultClassInfoFactory();
    }

    public function testFromAstNodeExtractsClassName(): void
    {
        $node = $this->parseClassFromFixture('src/Domain/User.php');

        $info = $this->factory->fromAstNode($node, 'file:///test.php');

        self::assertSame('Fixtures\\Domain\\User', $info->name->fqn);
        self::assertSame(ClassKind::Class_, $info->kind);
    }

    public function testFromAstNodeExtractsClassNameWithoutNameResolver(): void
    {
        $node = $this->parseClassWithoutNameResolverFromFixture('TypeInference/GlobalFunction.php');

        $info = $this->factory->fromAstNode($node, 'file:///test.php');

        self::assertSame('GlobalConfig', $info->name->fqn);
    }

    public function testFromAstNodeExtractsInterface(): void
    {
        $node = $this->parseClassFromFixture('src/Domain/Entity.php');

        $info = $this->factory->fromAstNode($node, 'file:///test.php');

        self::assertSame(ClassKind::Interface_, $info->kind);
    }

    public function testFromAstNodeExtractsTrait(): void
    {
        $node = $this->parseClassFromFixture('src/Traits/HasTimestamps.php');

        $info = $this->factory->fromAstNode($node, 'file:///test.php');

        self::assertSame(ClassKind::Trait_, $info->kind);
    }

    public function testFromAstNodeExtractsEnum(): void
    {
        $node = $this->parseClassFromFixture('src/Enum/Status.php');

        $info = $this->factory->fromAstNode($node, 'file:///test.php');

        self::assertSame(ClassKind::Enum_, $info->kind);
        self::assertGreaterThanOrEqual(1, count($info->enumCases));
        self::assertArrayHasKey('Active', $info->enumCases);
    }

    public function testFromAstNodeExtractsParentClass(): void
    {
        $node = $this->parseClassFromFixture('src/Inheritance/ParentClass.php');

        $info = $this->factory->fromAstNode($node, 'file:///test.php');

        self::assertNotNull($info->parent);
        self::assertSame('Fixtures\\Inheritance\\Grandparent', $info->parent->fqn);
    }

    public function testFromAstNodeExtractsImportedParentClass(): void
    {
        $node = $this->parseClassFromFixture('src/Utility/ImportedExtends.php');

        $info = $this->factory->fromAstNode($node, 'file:///test.php');

        self::assertNotNull($info->parent);
        self::assertSame('Fixtures\\Inheritance\\ParentClass', $info->parent->fqn);
    }

    public function testFromAstNodeExtractsAbstractClass(): void
    {
        $node = $this->parseClassFromFixture('src/Utility/ClassModifiers.php', 'AbstractBase');

        $info = $this->factory->fromAstNode($node, 'file:///test.php');

        self::assertTrue($info->isAbstract);
    }

    public function testFromAstNodeExtractsFinalClass(): void
    {
        $node = $this->parseClassFromFixture('src/Utility/ClassModifiers.php', 'SealedClass');

        $info = $this->factory->fromAstNode($node, 'file:///test.php');

        self::assertTrue($info->isFinal);
    }

    public function testFromAstNodeExtractsReadonlyClass(): void
    {
        $node = $this->parseClassFromFixture('src/Utility/ClassModifiers.php', 'ImmutableClass');

        $info = $this->factory->fromAstNode($node, 'file:///test.php');

        self::assertTrue($info->isReadonly);
    }

    public function testFromAstNodeExtractsInterfaces(): void
    {
        $node = $this->parseClassFromFixture('src/Domain/User.php');

        $info = $this->factory->fromAstNode($node, 'file:///test.php');

        self::assertCount(2, $info->interfaces);
        self::assertSame('Fixtures\\Domain\\Entity', $info->interfaces[0]->fqn);
        self::assertSame('Fixtures\\Domain\\Person', $info->interfaces[1]->fqn);
    }

    public function testFromAstNodeExtractsInterfaceExtends(): void
    {
        $node = $this->parseClassFromFixture('src/Repository/UserRepository.php');

        $info = $this->factory->fromAstNode($node, 'file:///test.php');

        self::assertSame(ClassKind::Interface_, $info->kind);
    }

    public function testFromAstNodeExtractsEnumImplements(): void
    {
        $node = $this->parseClassFromFixture('src/Enum/SerializableStatus.php');

        $info = $this->factory->fromAstNode($node, 'file:///test.php');

        self::assertSame(ClassKind::Enum_, $info->kind);
        self::assertCount(1, $info->interfaces);
        self::assertSame('JsonSerializable', $info->interfaces[0]->fqn);
        self::assertCount(2, $info->enumCases);
        self::assertArrayHasKey('Active', $info->enumCases);
        self::assertSame('active', $info->enumCases['Active']->backingValue);
    }

    public function testFromAstNodeExtractsIntBackedEnumCases(): void
    {
        $node = $this->parseClassFromFixture('src/Enum/Priority.php');

        $info = $this->factory->fromAstNode($node, 'file:///test.php');

        self::assertGreaterThanOrEqual(2, count($info->enumCases));
        self::assertArrayHasKey('Low', $info->enumCases);
        self::assertArrayHasKey('High', $info->enumCases);
        self::assertSame(1, $info->enumCases['Low']->backingValue);
        self::assertSame(10, $info->enumCases['High']->backingValue);
    }

    public function testFromAstNodeExtractsPureEnumCasesWithNullBackingValue(): void
    {
        $node = $this->parseClassFromFixture('src/Enum/Status.php');

        $info = $this->factory->fromAstNode($node, 'file:///test.php');

        self::assertGreaterThanOrEqual(2, count($info->enumCases));
        self::assertNull($info->enumCases['Active']->backingValue);
        self::assertNull($info->enumCases['Inactive']->backingValue);
    }

    public function testFromAstNodeSynthesizesEnumBuiltinMethods(): void
    {
        $node = $this->parseClassFromFixture('src/Enum/Status.php');

        $info = $this->factory->fromAstNode($node, 'file:///test.php');

        self::assertArrayHasKey('cases', $info->methods);
        self::assertTrue($info->methods['cases']->isStatic);
        self::assertSame('array', $info->methods['cases']->returnType?->format());
    }

    public function testFromAstNodeSynthesizesBackedEnumMethods(): void
    {
        $node = $this->parseClassFromFixture('src/Enum/Priority.php');

        $info = $this->factory->fromAstNode($node, 'file:///test.php');

        self::assertArrayHasKey('cases', $info->methods);
        self::assertArrayHasKey('from', $info->methods);
        self::assertArrayHasKey('tryFrom', $info->methods);

        self::assertTrue($info->methods['from']->isStatic);
        self::assertSame('Fixtures\\Enum\\Priority', $info->methods['from']->returnType?->format());
        self::assertCount(1, $info->methods['from']->parameters);
        self::assertSame('int', $info->methods['from']->parameters[0]->type?->format());

        self::assertSame('?Fixtures\\Enum\\Priority', $info->methods['tryFrom']->returnType?->format());
    }

    public function testFromAstNodeExtractsTraits(): void
    {
        $node = $this->parseClassFromFixture('src/Repository/ClassInfoPatterns.php', 'ClassInfoPatterns');

        $info = $this->factory->fromAstNode($node, 'file:///test.php');

        self::assertCount(1, $info->traits);
        self::assertSame('Fixtures\\Repository\\ExampleTrait', $info->traits[0]->fqn);
    }

    public function testFromAstNodeExtractsMethods(): void
    {
        $node = $this->parseClassFromFixture('src/Repository/ClassInfoPatterns.php', 'ClassInfoPatterns');

        $info = $this->factory->fromAstNode($node, 'file:///test.php');

        self::assertArrayHasKey('publicMethod', $info->methods);
        self::assertArrayHasKey('protectedMethod', $info->methods);
        self::assertArrayHasKey('privateStaticMethod', $info->methods);

        self::assertSame(Visibility::Public, $info->methods['publicMethod']->visibility);
        self::assertSame(Visibility::Protected, $info->methods['protectedMethod']->visibility);
        self::assertSame(Visibility::Private, $info->methods['privateStaticMethod']->visibility);
        self::assertTrue($info->methods['privateStaticMethod']->isStatic);
        self::assertSame('void', $info->methods['publicMethod']->returnType?->format());
        self::assertSame('string', $info->methods['protectedMethod']->returnType?->format());
    }

    public function testFromAstNodePreservesLateStaticSelfReturnType(): void
    {
        $node = $this->parseClassFromFixture('src/Repository/ClassInfoPatterns.php', 'ClassInfoPatterns');

        $info = $this->factory->fromAstNode($node, 'file:///test.php');

        self::assertArrayHasKey('createSelf', $info->methods);
        $returnType = $info->methods['createSelf']->returnType;
        self::assertNotNull($returnType);
        // Late-binding types preserve the keyword for display
        self::assertSame('?self', $returnType->format());
        // But still resolve to the declaring class for lookups
        $classNames = $returnType->getResolvableClassNames();
        self::assertCount(1, $classNames);
        self::assertSame('Fixtures\\Repository\\ClassInfoPatterns', $classNames[0]->fqn);
    }

    public function testFromAstNodePreservesLateStaticStaticReturnType(): void
    {
        $node = $this->parseClassFromFixture('src/Repository/ClassInfoPatterns.php', 'ClassInfoPatterns');

        $info = $this->factory->fromAstNode($node, 'file:///test.php');

        self::assertArrayHasKey('buildStatic', $info->methods);
        $returnType = $info->methods['buildStatic']->returnType;
        self::assertNotNull($returnType);
        // Late-binding types preserve the keyword for display
        self::assertSame('static', $returnType->format());
        // But still resolve to the declaring class for lookups
        $classNames = $returnType->getResolvableClassNames();
        self::assertCount(1, $classNames);
        self::assertSame('Fixtures\\Repository\\ClassInfoPatterns', $classNames[0]->fqn);
    }

    public function testFromAstNodeExtractsMethodParameters(): void
    {
        $node = $this->parseClassFromFixture('src/Repository/ClassInfoPatterns.php', 'ClassInfoPatterns');

        $info = $this->factory->fromAstNode($node, 'file:///test.php');
        $method = $info->methods['withParams'];

        self::assertCount(3, $method->parameters);
        self::assertSame('name', $method->parameters[0]->name);
        self::assertSame('string', $method->parameters[0]->type?->format());
        self::assertFalse($method->parameters[0]->hasDefault);
        self::assertFalse($method->parameters[0]->isVariadic);

        self::assertSame('count', $method->parameters[1]->name);
        self::assertTrue($method->parameters[1]->hasDefault);

        self::assertSame('items', $method->parameters[2]->name);
        self::assertTrue($method->parameters[2]->isVariadic);
    }

    public function testFromAstNodeExtractsProperties(): void
    {
        $node = $this->parseClassFromFixture('src/Repository/ClassInfoPatterns.php', 'ClassInfoPatterns');

        $info = $this->factory->fromAstNode($node, 'file:///test.php');

        self::assertArrayHasKey('publicProp', $info->properties);
        self::assertSame(Visibility::Public, $info->properties['publicProp']->visibility);
        self::assertSame('string', $info->properties['publicProp']->type?->format());
        self::assertFalse($info->properties['publicProp']->isPromoted);

        self::assertTrue($info->properties['privateStaticProp']->isStatic);
        self::assertTrue($info->properties['readonlyProp']->isReadonly);
    }

    public function testFromAstNodeExtractsPromotedProperties(): void
    {
        $node = $this->parseClassFromFixture('src/Repository/ClassInfoPatterns.php', 'ClassInfoPatterns');

        $info = $this->factory->fromAstNode($node, 'file:///test.php');

        self::assertArrayHasKey('name', $info->properties);
        self::assertArrayHasKey('id', $info->properties);

        self::assertTrue($info->properties['name']->isPromoted);
        self::assertSame(Visibility::Public, $info->properties['name']->visibility);

        self::assertTrue($info->properties['id']->isPromoted);
        self::assertTrue($info->properties['id']->isReadonly);
        self::assertSame(Visibility::Private, $info->properties['id']->visibility);
    }

    public function testFromAstNodeExtractsConstants(): void
    {
        $node = $this->parseClassFromFixture('src/Repository/ClassInfoPatterns.php', 'ClassInfoPatterns');

        $info = $this->factory->fromAstNode($node, 'file:///test.php');

        self::assertArrayHasKey('PUBLIC_CONST', $info->constants);
        self::assertSame(Visibility::Public, $info->constants['PUBLIC_CONST']->visibility);
        self::assertSame('string', $info->constants['PUBLIC_CONST']->type?->format());

        self::assertSame(Visibility::Protected, $info->constants['PROTECTED_CONST']->visibility);
        self::assertSame(Visibility::Private, $info->constants['PRIVATE_CONST']->visibility);
        self::assertNull($info->constants['PRIVATE_CONST']->type);
    }

    public function testFromAstNodeExtractsDocblock(): void
    {
        $node = $this->parseClassFromFixture('src/Domain/User.php');

        $info = $this->factory->fromAstNode($node, 'file:///test.php');

        self::assertStringContainsString('Represents a system user', $info->docblock ?? '');
    }

    public function testFromAstNodeExtractsFileInfo(): void
    {
        $node = $this->parseClassFromFixture('src/Domain/User.php');

        $info = $this->factory->fromAstNode($node, 'file:///path/to/test.php');

        self::assertSame('/path/to/test.php', $info->file);
        self::assertNotNull($info->line);
    }

    public function testFromAstNodeThrowsForAnonymousClass(): void
    {
        $code = $this->loadFixture('src/Utility/AnonymousClassScope.php');
        $parser = (new ParserFactory())->createForNewestSupportedVersion();
        $ast = $parser->parse($code);
        assert($ast !== null);

        $node = $this->extractAnonymousClassNode($ast);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('anonymous class');

        $this->factory->fromAstNode($node, 'file:///test.php');
    }

    public function testFromReflectionExtractsBasicInfo(): void
    {
        $reflection = new ReflectionClass(\stdClass::class);

        $info = $this->factory->fromReflection($reflection);

        self::assertSame(\stdClass::class, $info->name->fqn);
        self::assertSame(ClassKind::Class_, $info->kind);
    }

    public function testFromReflectionExtractsInterface(): void
    {
        $reflection = new ReflectionClass(\Iterator::class);

        $info = $this->factory->fromReflection($reflection);

        self::assertSame(ClassKind::Interface_, $info->kind);
    }

    public function testFromReflectionExtractsTrait(): void
    {
        $reflection = new ReflectionClass(TestTrait::class);

        $info = $this->factory->fromReflection($reflection);

        self::assertSame(ClassKind::Trait_, $info->kind);
    }

    public function testFromReflectionExtractsEnum(): void
    {
        $reflection = new ReflectionClass(TestEnum::class);

        $info = $this->factory->fromReflection($reflection);

        self::assertSame(ClassKind::Enum_, $info->kind);
        self::assertCount(2, $info->enumCases);
        self::assertArrayHasKey('Foo', $info->enumCases);
        self::assertArrayHasKey('Bar', $info->enumCases);
        self::assertNull($info->enumCases['Foo']->backingValue);
    }

    public function testFromReflectionExtractsBackedEnum(): void
    {
        $reflection = new ReflectionClass(TestBackedEnum::class);

        $info = $this->factory->fromReflection($reflection);

        self::assertSame(ClassKind::Enum_, $info->kind);
        self::assertCount(2, $info->enumCases);
        self::assertSame(1, $info->enumCases['Low']->backingValue);
        self::assertSame(10, $info->enumCases['High']->backingValue);
    }

    public function testFromReflectionExtractsEnumBuiltinMethods(): void
    {
        $reflection = new ReflectionClass(TestBackedEnum::class);

        $info = $this->factory->fromReflection($reflection);

        self::assertArrayHasKey('cases', $info->methods);
        self::assertArrayHasKey('from', $info->methods);
        self::assertArrayHasKey('tryFrom', $info->methods);
        self::assertTrue($info->methods['cases']->isStatic);
    }

    public function testFromReflectionExtractsMethods(): void
    {
        $reflection = new ReflectionClass(TestClass::class);

        $info = $this->factory->fromReflection($reflection);

        self::assertArrayHasKey('publicMethod', $info->methods);
        self::assertSame(Visibility::Public, $info->methods['publicMethod']->visibility);
        self::assertSame('void', $info->methods['publicMethod']->returnType?->format());
    }

    public function testFromReflectionExtractsProperties(): void
    {
        $reflection = new ReflectionClass(TestClass::class);

        $info = $this->factory->fromReflection($reflection);

        self::assertArrayHasKey('publicProp', $info->properties);
        self::assertSame(Visibility::Public, $info->properties['publicProp']->visibility);
        self::assertSame('string', $info->properties['publicProp']->type?->format());
    }

    public function testFromReflectionExtractsConstants(): void
    {
        $reflection = new ReflectionClass(TestClass::class);

        $info = $this->factory->fromReflection($reflection);

        self::assertArrayHasKey('TEST_CONST', $info->constants);
        self::assertSame(Visibility::Public, $info->constants['TEST_CONST']->visibility);
    }

    public function testFromReflectionExtractsTypedConstants(): void
    {
        $reflection = new ReflectionClass(TestClass::class);

        $info = $this->factory->fromReflection($reflection);

        self::assertArrayHasKey('TYPED_CONST', $info->constants);
        self::assertSame('string', $info->constants['TYPED_CONST']->type?->format());
    }

    public function testFromReflectionExtractsInterfaces(): void
    {
        $reflection = new ReflectionClass(ClassWithInterface::class);

        $info = $this->factory->fromReflection($reflection);

        self::assertCount(1, $info->interfaces);
        self::assertSame(\Countable::class, $info->interfaces[0]->fqn);
    }

    public function testFromReflectionExtractsTraits(): void
    {
        $reflection = new ReflectionClass(TestClass::class);

        $info = $this->factory->fromReflection($reflection);

        self::assertCount(1, $info->traits);
        self::assertSame(TestTrait::class, $info->traits[0]->fqn);
    }

    private function parseClassFromFixture(string $fixturePath, ?string $className = null): Stmt\ClassLike
    {
        $code = $this->loadFixture($fixturePath);
        return $this->parseClassInternal($code, $className, useNameResolver: true);
    }

    private function parseClassWithoutNameResolverFromFixture(
        string $fixturePath,
        ?string $className = null,
    ): Stmt\ClassLike {
        $code = $this->loadFixture($fixturePath);
        return $this->parseClassInternal($code, $className, useNameResolver: false);
    }

    private function parseClassInternal(string $code, ?string $className, bool $useNameResolver): Stmt\ClassLike
    {
        $parser = (new ParserFactory())->createForNewestSupportedVersion();
        $ast = $parser->parse($code);
        assert($ast !== null);

        if ($useNameResolver) {
            $traverser = new \PhpParser\NodeTraverser();
            $traverser->addVisitor(new \PhpParser\NodeVisitor\NameResolver());
            $ast = $traverser->traverse($ast);
        }

        foreach ($ast as $stmt) {
            if ($stmt instanceof Stmt\Namespace_) {
                foreach ($stmt->stmts as $nsStmt) {
                    if ($nsStmt instanceof Stmt\ClassLike) {
                        if ($className === null || $nsStmt->name?->toString() === $className) {
                            return $nsStmt;
                        }
                    }
                }
            }
            if ($stmt instanceof Stmt\ClassLike) {
                if ($className === null || $stmt->name?->toString() === $className) {
                    return $stmt;
                }
            }
        }

        throw new \RuntimeException('No class found in code');
    }

    /**
     * @param array<\PhpParser\Node\Stmt> $ast
     */
    private function extractAnonymousClassNode(array $ast): Stmt\Class_
    {
        $finder = new \PhpParser\NodeFinder();
        $class = $finder->findFirst($ast, fn($node) => $node instanceof Stmt\Class_ && $node->name === null);
        assert($class instanceof Stmt\Class_);
        return $class;
    }
}
