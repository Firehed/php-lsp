<?php

declare(strict_types=1);

namespace Firehed\PhpLsp\Tests\Resolution;

use Firehed\PhpLsp\Document\TextDocument;
use Firehed\PhpLsp\Domain\ClassName;
use Firehed\PhpLsp\Domain\Visibility;
use Firehed\PhpLsp\Parser\ParserService;
use Firehed\PhpLsp\Repository\ClassLocator;
use Firehed\PhpLsp\Repository\DefaultClassInfoFactory;
use Firehed\PhpLsp\Repository\DefaultClassRepository;
use Firehed\PhpLsp\Repository\MemberResolver;
use Firehed\PhpLsp\Resolution\MemberAccessKind;
use Firehed\PhpLsp\Resolution\MemberFilter;
use Firehed\PhpLsp\Resolution\ResolvedConstant;
use Firehed\PhpLsp\Resolution\ResolvedMethod;
use Firehed\PhpLsp\Resolution\ResolvedProperty;
use Firehed\PhpLsp\Resolution\TextFallbackHelper;
use Firehed\PhpLsp\Tests\LoadsFixturesTrait;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

#[CoversClass(TextFallbackHelper::class)]
final class TextFallbackHelperTest extends TestCase
{
    use LoadsFixturesTrait;

    private TextFallbackHelper $helper;
    private ParserService $parser;
    private MemberResolver $memberResolver;

    protected function setUp(): void
    {
        $this->parser = new ParserService();
        $classInfoFactory = new DefaultClassInfoFactory();
        $locator = self::createStub(ClassLocator::class);
        $classRepository = new DefaultClassRepository(
            $classInfoFactory,
            $locator,
            $this->parser,
        );
        $this->memberResolver = new MemberResolver($classRepository);
        $this->helper = new TextFallbackHelper($this->memberResolver);
    }

    public function testFindEnclosingClassFromContentFindsClass(): void
    {
        $content = <<<'PHP'
<?php

namespace App;

class MyClass
{
    public function method(): void
    {
        $this->
    }
}
PHP;
        $result = $this->helper->findEnclosingClassFromContent($content, 8);

        self::assertSame('App\\MyClass', $result);
    }

    public function testFindEnclosingClassFromContentFindsAbstractClass(): void
    {
        $content = <<<'PHP'
<?php

namespace App;

abstract class AbstractClass
{
    public function method(): void
    {
        $this->
    }
}
PHP;
        $result = $this->helper->findEnclosingClassFromContent($content, 8);

        self::assertSame('App\\AbstractClass', $result);
    }

    public function testFindEnclosingClassFromContentFindsFinalReadonlyClass(): void
    {
        $content = <<<'PHP'
<?php

namespace App;

final readonly class MyClass
{
    public function method(): void {}
}
PHP;
        $result = $this->helper->findEnclosingClassFromContent($content, 6);

        self::assertSame('App\\MyClass', $result);
    }

    public function testFindEnclosingClassFromContentFindsTrait(): void
    {
        $content = <<<'PHP'
<?php

namespace App\Traits;

trait MyTrait
{
    public function method(): void
    {
        $this->
    }
}
PHP;
        $result = $this->helper->findEnclosingClassFromContent($content, 8);

        self::assertSame('App\\Traits\\MyTrait', $result);
    }

    public function testFindEnclosingClassFromContentFindsEnum(): void
    {
        $content = <<<'PHP'
<?php

namespace App;

enum Status: string
{
    case Active = 'active';
}
PHP;
        $result = $this->helper->findEnclosingClassFromContent($content, 6);

        self::assertSame('App\\Status', $result);
    }

    public function testFindEnclosingClassFromContentReturnsNullOutsideClass(): void
    {
        $content = <<<'PHP'
<?php

namespace App;

function globalFunction(): void
{
    $x = 1;
}
PHP;
        $result = $this->helper->findEnclosingClassFromContent($content, 6);

        self::assertNull($result);
    }

    public function testFindEnclosingClassFromContentWithNoNamespace(): void
    {
        $content = <<<'PHP'
<?php

class GlobalClass
{
    public function method(): void {}
}
PHP;
        $result = $this->helper->findEnclosingClassFromContent($content, 4);

        self::assertSame('GlobalClass', $result);
    }

    public function testFindNamespaceFindsDeclaration(): void
    {
        $lines = [
            '<?php',
            '',
            'namespace App\\Services;',
            '',
            'class MyService {}',
        ];

        $result = $this->helper->findNamespace($lines, 4);

        self::assertSame('App\\Services', $result);
    }

    public function testFindNamespaceWithBracedSyntax(): void
    {
        $lines = [
            '<?php',
            '',
            'namespace App\\Services {',
            '',
            'class MyService {}',
            '',
            '}',
        ];

        $result = $this->helper->findNamespace($lines, 4);

        self::assertSame('App\\Services', $result);
    }

    public function testFindNamespaceReturnsNullWhenNone(): void
    {
        $lines = [
            '<?php',
            '',
            'class GlobalClass {}',
        ];

        $result = $this->helper->findNamespace($lines, 2);

        self::assertNull($result);
    }

    public function testExtractMembersExtractsPublicMethods(): void
    {
        $content = <<<'PHP'
<?php

namespace Test;

class MyClass
{
    public function publicMethod(): void {}
    protected function protectedMethod(): void {}
    private function privateMethod(): void {}
}
PHP;
        $document = new TextDocument('file:///test.php', 'php', 1, $content);
        // @phpstan-ignore argument.type (test fixture class)
        $className = new ClassName('Test\\MyClass');

        $members = $this->helper->extractMembers($document, $className, Visibility::Public);

        self::assertCount(1, $members);
        self::assertInstanceOf(ResolvedMethod::class, $members[0]);
        self::assertSame('publicMethod', $members[0]->getName()->name);
    }

    public function testExtractMembersExtractsProtectedMembers(): void
    {
        $content = <<<'PHP'
<?php

namespace Test;

class MyClass
{
    public function publicMethod(): void {}
    protected function protectedMethod(): void {}
    private function privateMethod(): void {}
}
PHP;
        $document = new TextDocument('file:///test.php', 'php', 1, $content);
        // @phpstan-ignore argument.type (test fixture class)
        $className = new ClassName('Test\\MyClass');

        $members = $this->helper->extractMembers($document, $className, Visibility::Protected);

        self::assertCount(2, $members, 'Should include public and protected');
        $names = array_map(fn($m) => $m->getName()->name, $members);
        self::assertContains('publicMethod', $names);
        self::assertContains('protectedMethod', $names);
    }

    public function testExtractMembersExtractsPrivateMembers(): void
    {
        $content = <<<'PHP'
<?php

namespace Test;

class MyClass
{
    public function publicMethod(): void {}
    protected function protectedMethod(): void {}
    private function privateMethod(): void {}
}
PHP;
        $document = new TextDocument('file:///test.php', 'php', 1, $content);
        // @phpstan-ignore argument.type (test fixture class)
        $className = new ClassName('Test\\MyClass');

        $members = $this->helper->extractMembers($document, $className, Visibility::Private);

        self::assertCount(3, $members, 'Should include all methods');
    }

    public function testExtractMembersExtractsProperties(): void
    {
        $content = <<<'PHP'
<?php

namespace Test;

class MyClass
{
    public string $name;
    protected int $count;
    private bool $active;
    public static string $staticProp;
}
PHP;
        $document = new TextDocument('file:///test.php', 'php', 1, $content);
        // @phpstan-ignore argument.type (test fixture class)
        $className = new ClassName('Test\\MyClass');

        $members = $this->helper->extractMembers(
            $document,
            $className,
            Visibility::Private,
            MemberFilter::Instance,
        );

        $properties = array_filter($members, fn($m) => $m instanceof ResolvedProperty);
        self::assertCount(3, $properties, 'Should include 3 instance properties, not static');
    }

    public function testExtractMembersExtractsStaticMembers(): void
    {
        $content = <<<'PHP'
<?php

namespace Test;

class MyClass
{
    public static function staticMethod(): void {}
    public function instanceMethod(): void {}
    public static string $staticProp;
    public string $instanceProp;
    public const CONSTANT = 'value';
}
PHP;
        $document = new TextDocument('file:///test.php', 'php', 1, $content);
        // @phpstan-ignore argument.type (test fixture class)
        $className = new ClassName('Test\\MyClass');

        $members = $this->helper->extractMembers(
            $document,
            $className,
            Visibility::Public,
            MemberFilter::Static,
        );

        self::assertNotEmpty($members);
        $names = array_map(fn($m) => $m->getName()->name, $members);
        self::assertContains('staticMethod', $names, 'Should include static method');
        self::assertContains('CONSTANT', $names, 'Should include constant');
        self::assertNotContains('instanceMethod', $names, 'Should not include instance method');
    }

    public function testExtractMembersExtractsConstants(): void
    {
        $content = <<<'PHP'
<?php

namespace Test;

class MyClass
{
    public const PUBLIC_CONST = 1;
    protected const PROTECTED_CONST = 2;
    private const PRIVATE_CONST = 3;
    const IMPLICIT_PUBLIC = 4;
}
PHP;
        $document = new TextDocument('file:///test.php', 'php', 1, $content);
        // @phpstan-ignore argument.type (test fixture class)
        $className = new ClassName('Test\\MyClass');

        $members = $this->helper->extractMembers(
            $document,
            $className,
            Visibility::Public,
            MemberFilter::Static,
        );

        $constants = array_filter($members, fn($m) => $m instanceof ResolvedConstant);
        self::assertCount(2, $constants, 'Should include PUBLIC_CONST and IMPLICIT_PUBLIC');
    }

    public function testExtractMembersExtractsTypedConstants(): void
    {
        $content = <<<'PHP'
<?php

namespace Test;

class MyClass
{
    public const string TYPED_CONST = 'value';
}
PHP;
        $document = new TextDocument('file:///test.php', 'php', 1, $content);
        // @phpstan-ignore argument.type (test fixture class)
        $className = new ClassName('Test\\MyClass');

        $members = $this->helper->extractMembers(
            $document,
            $className,
            Visibility::Public,
            MemberFilter::Static,
        );

        $constants = array_filter($members, fn($m) => $m instanceof ResolvedConstant);
        self::assertCount(1, $constants);
        $constant = array_values($constants)[0];
        self::assertSame('TYPED_CONST', $constant->getName()->name);
    }

    public function testExtractMembersReturnsEmptyForUnknownClass(): void
    {
        $content = <<<'PHP'
<?php

namespace Test;

class MyClass {}
PHP;
        $document = new TextDocument('file:///test.php', 'php', 1, $content);
        // @phpstan-ignore argument.type (test fixture class)
        $className = new ClassName('Test\\UnknownClass');

        $members = $this->helper->extractMembers($document, $className, Visibility::Public);

        self::assertSame([], $members);
    }

    public function testExtractMembersWithReadonlyProperties(): void
    {
        $content = <<<'PHP'
<?php

namespace Test;

class MyClass
{
    public readonly string $name;
    protected readonly int $id;
}
PHP;
        $document = new TextDocument('file:///test.php', 'php', 1, $content);
        // @phpstan-ignore argument.type (test fixture class)
        $className = new ClassName('Test\\MyClass');

        $members = $this->helper->extractMembers($document, $className, Visibility::Private);

        $properties = array_filter($members, fn($m) => $m instanceof ResolvedProperty);
        self::assertCount(2, $properties);
    }

    public function testExtractMembersWithStaticMethods(): void
    {
        $content = <<<'PHP'
<?php

namespace Test;

class MyClass
{
    public static function create(): self {}
    private static function helper(): void {}
}
PHP;
        $document = new TextDocument('file:///test.php', 'php', 1, $content);
        // @phpstan-ignore argument.type (test fixture class)
        $className = new ClassName('Test\\MyClass');

        $members = $this->helper->extractMembers(
            $document,
            $className,
            Visibility::Public,
            MemberFilter::Static,
        );

        $methods = array_filter($members, fn($m) => $m instanceof ResolvedMethod);
        self::assertCount(1, $methods, 'Should only include public static method');
        $method = array_values($methods)[0];
        self::assertSame('create', $method->getName()->name);
        self::assertTrue($method->isStatic());
    }

    public function testGetMemberAccessContextForSimpleThisAccess(): void
    {
        $content = <<<'PHP'
<?php

namespace Test;

class MyClass
{
    public function method(): void
    {
        $this->
    }
}
PHP;
        $document = new TextDocument('file:///test.php', 'php', 1, $content);

        $context = $this->helper->getMemberAccessContext($document, 8, 15, []);

        self::assertNotNull($context);
        self::assertSame('Test\\MyClass', $context->type->format());
        self::assertSame(MemberAccessKind::Instance, $context->kind);
        self::assertSame(Visibility::Private, $context->minVisibility);
    }

    public function testGetMemberAccessContextForThisWithPrefix(): void
    {
        $content = <<<'PHP'
<?php

namespace Test;

class MyClass
{
    public function method(): void
    {
        $this->get
    }
}
PHP;
        $document = new TextDocument('file:///test.php', 'php', 1, $content);

        $context = $this->helper->getMemberAccessContext($document, 8, 18, []);

        self::assertNotNull($context);
        self::assertSame('get', $context->prefix);
    }

    public function testGetMemberAccessContextForNullsafeAccess(): void
    {
        $content = <<<'PHP'
<?php

namespace Test;

class MyClass
{
    public function method(): void
    {
        $this?->
    }
}
PHP;
        $document = new TextDocument('file:///test.php', 'php', 1, $content);

        $context = $this->helper->getMemberAccessContext($document, 8, 16, []);

        self::assertNotNull($context);
        self::assertSame(MemberAccessKind::Instance, $context->kind);
    }

    public function testGetMemberAccessContextReturnsNullForOtherVariables(): void
    {
        $content = <<<'PHP'
<?php

namespace Test;

class MyClass
{
    public function method(): void
    {
        $other->
    }
}
PHP;
        $document = new TextDocument('file:///test.php', 'php', 1, $content);

        $context = $this->helper->getMemberAccessContext($document, 8, 16, []);

        self::assertNull($context, 'Other variables need AST resolution');
    }

    public function testGetMemberAccessContextForSelfStatic(): void
    {
        $content = <<<'PHP'
<?php

namespace Test;

class MyClass
{
    public function method(): void
    {
        self::
    }
}
PHP;
        $document = new TextDocument('file:///test.php', 'php', 1, $content);

        $context = $this->helper->getMemberAccessContext($document, 8, 14, []);

        self::assertNotNull($context);
        self::assertSame('Test\\MyClass', $context->type->format());
        self::assertSame(MemberAccessKind::Static, $context->kind);
        self::assertSame(Visibility::Private, $context->minVisibility);
    }

    public function testGetMemberAccessContextForStaticKeyword(): void
    {
        $content = <<<'PHP'
<?php

namespace Test;

class MyClass
{
    public function method(): void
    {
        static::
    }
}
PHP;
        $document = new TextDocument('file:///test.php', 'php', 1, $content);

        $context = $this->helper->getMemberAccessContext($document, 8, 16, []);

        self::assertNotNull($context);
        self::assertSame('Test\\MyClass', $context->type->format());
        self::assertSame(MemberAccessKind::Static, $context->kind);
    }

    public function testGetMemberAccessContextReturnsNullForSelfOutsideClass(): void
    {
        $content = <<<'PHP'
<?php

function globalFunction(): void
{
    self::
}
PHP;
        $document = new TextDocument('file:///test.php', 'php', 1, $content);

        $context = $this->helper->getMemberAccessContext($document, 4, 10, []);

        self::assertNull($context);
    }

    public function testGetMemberAccessContextForExternalClass(): void
    {
        $content = <<<'PHP'
<?php

namespace Test;

use App\Services\Logger;

class MyClass
{
    public function method(): void
    {
        Logger::
    }
}
PHP;
        $document = new TextDocument('file:///test.php', 'php', 1, $content);
        $ast = $this->parser->parse($document) ?? [];

        $context = $this->helper->getMemberAccessContext($document, 10, 16, $ast);

        self::assertNotNull($context);
        self::assertSame('App\\Services\\Logger', $context->type->format());
        self::assertSame(MemberAccessKind::Static, $context->kind);
        self::assertSame(Visibility::Public, $context->minVisibility);
    }

    public function testGetMemberAccessContextForSameClassStatic(): void
    {
        $content = <<<'PHP'
<?php

namespace Test;

class MyClass
{
    public function method(): void
    {
        MyClass::
    }
}
PHP;
        $document = new TextDocument('file:///test.php', 'php', 1, $content);

        $context = $this->helper->getMemberAccessContext($document, 8, 17, []);

        self::assertNotNull($context);
        self::assertSame('Test\\MyClass', $context->type->format());
        self::assertSame(Visibility::Private, $context->minVisibility);
    }

    public function testGetMemberAccessContextForParentWithAst(): void
    {
        $content = <<<'PHP'
<?php

namespace Test;

class ParentClass {}

class ChildClass extends ParentClass
{
    public function method(): void
    {
        parent::
    }
}
PHP;
        $document = new TextDocument('file:///test.php', 'php', 1, $content);
        $ast = $this->parser->parse($document) ?? [];

        $context = $this->helper->getMemberAccessContext($document, 10, 16, $ast);

        self::assertNotNull($context);
        self::assertSame('Test\\ParentClass', $context->type->format());
        self::assertSame(MemberAccessKind::Parent, $context->kind);
        self::assertSame(Visibility::Protected, $context->minVisibility);
    }

    public function testGetMemberAccessContextForParentWithoutExtends(): void
    {
        $content = <<<'PHP'
<?php

namespace Test;

class MyClass
{
    public function method(): void
    {
        parent::
    }
}
PHP;
        $document = new TextDocument('file:///test.php', 'php', 1, $content);
        $ast = $this->parser->parse($document) ?? [];

        $context = $this->helper->getMemberAccessContext($document, 8, 15, $ast);

        self::assertNull($context);
    }

    public function testFindParameterTypeFindsTypedParameter(): void
    {
        $content = <<<'PHP'
<?php

namespace Test;

use App\User;

class MyClass
{
    public function method(User $user): void
    {
        $user->
    }
}
PHP;
        $document = new TextDocument('file:///test.php', 'php', 1, $content);
        $ast = $this->parser->parse($document) ?? [];

        $type = $this->helper->findParameterType($document, 10, 'user', $ast);

        self::assertNotNull($type);
        self::assertSame('App\\User', $type->format());
    }

    public function testFindParameterTypeFindsNullableParameter(): void
    {
        $content = <<<'PHP'
<?php

namespace Test;

use App\User;

class MyClass
{
    public function method(?User $user): void
    {
        $user->
    }
}
PHP;
        $document = new TextDocument('file:///test.php', 'php', 1, $content);
        $ast = $this->parser->parse($document) ?? [];

        $type = $this->helper->findParameterType($document, 10, 'user', $ast);

        self::assertNotNull($type);
        self::assertSame('App\\User', $type->format());
    }

    public function testFindParameterTypeReturnsNullForPrimitive(): void
    {
        $content = <<<'PHP'
<?php

namespace Test;

class MyClass
{
    public function method(string $name): void
    {
        $name->
    }
}
PHP;
        $document = new TextDocument('file:///test.php', 'php', 1, $content);
        $ast = $this->parser->parse($document) ?? [];

        $type = $this->helper->findParameterType($document, 8, 'name', $ast);

        self::assertNull($type, 'Primitives have no members');
    }

    public function testFindParameterTypeWithMultiLineDeclaration(): void
    {
        $content = <<<'PHP'
<?php

namespace Test;

use App\User;
use App\Logger;

class MyClass
{
    public function method(
        User $user,
        Logger $logger
    ): void {
        $logger->
    }
}
PHP;
        $document = new TextDocument('file:///test.php', 'php', 1, $content);
        $ast = $this->parser->parse($document) ?? [];

        $type = $this->helper->findParameterType($document, 13, 'logger', $ast);

        self::assertNotNull($type);
        self::assertSame('App\\Logger', $type->format());
    }

    public function testFindParameterTypeReturnsNullForUnknownVariable(): void
    {
        $content = <<<'PHP'
<?php

namespace Test;

class MyClass
{
    public function method(): void
    {
        $unknown->
    }
}
PHP;
        $document = new TextDocument('file:///test.php', 'php', 1, $content);
        $ast = $this->parser->parse($document) ?? [];

        $type = $this->helper->findParameterType($document, 8, 'unknown', $ast);

        self::assertNull($type);
    }

    /**
     * @codeCoverageIgnore
     * @return iterable<string, array{string}>
     */
    public static function primitiveTypesProvider(): iterable
    {
        yield 'string' => ['string'];
        yield 'int' => ['int'];
        yield 'float' => ['float'];
        yield 'bool' => ['bool'];
        yield 'array' => ['array'];
        yield 'object' => ['object'];
        yield 'callable' => ['callable'];
        yield 'iterable' => ['iterable'];
        yield 'mixed' => ['mixed'];
        yield 'null' => ['null'];
        yield 'true' => ['true'];
        yield 'false' => ['false'];
    }

    #[DataProvider('primitiveTypesProvider')]
    public function testFindParameterTypeReturnsNullForAllPrimitives(string $primitive): void
    {
        $content = <<<PHP
<?php

namespace Test;

class MyClass
{
    public function method({$primitive} \$param): void
    {
        \$param->
    }
}
PHP;
        $document = new TextDocument('file:///test.php', 'php', 1, $content);
        $ast = $this->parser->parse($document) ?? [];

        $type = $this->helper->findParameterType($document, 8, 'param', $ast);

        self::assertNull($type, "Primitive type '$primitive' should return null");
    }

    public function testResolveChainTypeReturnsNullForNonThisChain(): void
    {
        // @phpstan-ignore argument.type (test fixture class)
        $type = $this->helper->resolveChainType('$other->prop', 'App\\MyClass');

        self::assertNull($type);
    }

    public function testResolveChainTypeReturnsNullForUnknownMember(): void
    {
        // @phpstan-ignore argument.type (test fixture class)
        $type = $this->helper->resolveChainType('$this->unknownProperty', 'Fixtures\\Domain\\User');

        self::assertNull($type);
    }

    public function testExtractMembersWithAllFilter(): void
    {
        $content = <<<'PHP'
<?php

namespace Test;

class MyClass
{
    public function instanceMethod(): void {}
    public static function staticMethod(): void {}
}
PHP;
        $document = new TextDocument('file:///test.php', 'php', 1, $content);
        // @phpstan-ignore argument.type (test fixture class)
        $className = new ClassName('Test\\MyClass');

        $members = $this->helper->extractMembers(
            $document,
            $className,
            Visibility::Public,
            MemberFilter::All,
        );

        $names = array_map(fn($m) => $m->getName()->name, $members);
        self::assertContains('instanceMethod', $names);
        self::assertContains('staticMethod', $names);
    }

    public function testExtractMembersFiltersStaticProperties(): void
    {
        $content = <<<'PHP'
<?php

namespace Test;

class MyClass
{
    public string $instanceProp;
    public static string $staticProp;
}
PHP;
        $document = new TextDocument('file:///test.php', 'php', 1, $content);
        // @phpstan-ignore argument.type (test fixture class)
        $className = new ClassName('Test\\MyClass');

        $members = $this->helper->extractMembers(
            $document,
            $className,
            Visibility::Public,
            MemberFilter::Static,
        );

        $properties = array_filter($members, fn($m) => $m instanceof ResolvedProperty);
        self::assertCount(0, $properties, 'Static filter should not include any properties');
    }

    public function testGetMemberAccessContextResolvesAliasedUse(): void
    {
        $content = <<<'PHP'
<?php

namespace Test;

use App\Services\Logger as Log;

class MyClass
{
    public function method(): void
    {
        Log::
    }
}
PHP;
        $document = new TextDocument('file:///test.php', 'php', 1, $content);

        $context = $this->helper->getMemberAccessContext($document, 10, 13, []);

        self::assertNotNull($context);
        self::assertSame('App\\Services\\Logger', $context->type->format());
    }

    public function testGetMemberAccessContextResolvesFullyQualifiedName(): void
    {
        $content = <<<'PHP'
<?php

namespace Test;

class MyClass
{
    public function method(): void
    {
        \App\Services\Logger::
    }
}
PHP;
        $document = new TextDocument('file:///test.php', 'php', 1, $content);

        $context = $this->helper->getMemberAccessContext($document, 8, 30, []);

        self::assertNotNull($context);
        self::assertSame('App\\Services\\Logger', $context->type->format());
    }

    public function testGetMemberAccessContextResolvesUnimportedClassWithNamespace(): void
    {
        $content = <<<'PHP'
<?php

namespace Test;

class MyClass
{
    public function method(): void
    {
        UnknownClass::
    }
}
PHP;
        $document = new TextDocument('file:///test.php', 'php', 1, $content);

        $context = $this->helper->getMemberAccessContext($document, 8, 22, []);

        self::assertNotNull($context);
        self::assertSame('Test\\UnknownClass', $context->type->format());
    }
}
