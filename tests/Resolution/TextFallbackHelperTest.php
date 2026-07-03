<?php

declare(strict_types=1);

namespace Firehed\PhpLsp\Tests\Resolution;

use Firehed\PhpLsp\Document\TextDocument;
use Firehed\PhpLsp\Domain\ClassInfo;
use Firehed\PhpLsp\Domain\ClassName;
use Firehed\PhpLsp\Domain\Visibility;
use Firehed\PhpLsp\Index\ComposerClassLocator;
use Firehed\PhpLsp\Parser\ParserService;
use Firehed\PhpLsp\Resolution\MemberFilter;
use Firehed\PhpLsp\Repository\ClassRepository;
use Firehed\PhpLsp\Repository\DefaultClassInfoFactory;
use Firehed\PhpLsp\Repository\DefaultClassRepository;
use Firehed\PhpLsp\Repository\MemberResolver;
use Firehed\PhpLsp\Resolution\TextFallbackHelper;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(TextFallbackHelper::class)]
class TextFallbackHelperTest extends TestCase
{
    private TextFallbackHelper $helper;
    private TextFallbackHelper $helperWithReflection;
    private ParserService $parser;

    protected function setUp(): void
    {
        // Create a minimal MemberResolver with a stub ClassRepository
        $classRepository = self::createStub(ClassRepository::class);
        $classRepository->method('get')->willReturn(null);
        $this->helper = new TextFallbackHelper(new MemberResolver($classRepository));

        // Create helper with fixture-based class resolution for chain tests
        $factory = new DefaultClassInfoFactory();
        $locator = new ComposerClassLocator(__DIR__ . '/../Fixtures');
        $this->parser = new ParserService();
        $fixtureRepo = new DefaultClassRepository($factory, $locator, $this->parser);
        $this->helperWithReflection = new TextFallbackHelper(new MemberResolver($fixtureRepo));
    }

    public function testFindEnclosingClassFromContentReturnsNullForCodeOutsideClass(): void
    {
        $content = $this->loadFixture('TopLevel/this_outside_class.php');
        $result = $this->helper->findEnclosingClassFromContent($content, 1);
        self::assertNull($result);
    }

    public function testFindEnclosingClassFromContentFindsClassWithNamespace(): void
    {
        $content = $this->loadFixture('TopLevel/class_with_namespace.php');
        // Line 3 is inside class Foo in namespace App
        $result = $this->helper->findEnclosingClassFromContent($content, 3);
        self::assertSame('App\\Foo', $result);
    }

    public function testFindEnclosingClassFromContentFindsClassWithoutNamespace(): void
    {
        $content = $this->loadFixture('TopLevel/class_without_namespace.php');
        // Line 2 is inside class GlobalClass
        $result = $this->helper->findEnclosingClassFromContent($content, 2);
        self::assertSame('GlobalClass', $result);
    }

    public function testFindNamespaceReturnsNullWhenNoNamespace(): void
    {
        $content = $this->loadFixture('TopLevel/no_namespace.php');
        $lines = explode("\n", $content);
        $result = $this->helper->findNamespace($lines, 2);
        self::assertNull($result);
    }

    public function testFindNamespaceFindsNamespaceWithSemicolon(): void
    {
        $content = $this->loadFixture('TopLevel/namespace_semicolon.php');
        $lines = explode("\n", $content);
        $result = $this->helper->findNamespace($lines, 3);
        self::assertSame('App\\Services', $result);
    }

    public function testFindNamespaceFindsNamespaceWithBrace(): void
    {
        $content = $this->loadFixture('TopLevel/namespace_brace.php');
        $lines = explode("\n", $content);
        $result = $this->helper->findNamespace($lines, 3);
        self::assertSame('App\\Services', $result);
    }

    public function testResolveChainTypeReturnsClassForSimpleThis(): void
    {
        // $this-> with nothing after returns the class type
        // @phpstan-ignore argument.type (test uses fake class name)
        $result = $this->helper->resolveChainType('$this->', 'App\\Foo');
        self::assertNotNull($result);
        self::assertSame('App\\Foo', $result->format());
    }

    public function testExtractMembersReturnsEmptyForNonMatchingClass(): void
    {
        $content = $this->loadFixture('TopLevel/empty_class.php');
        $document = new TextDocument('file:///test.php', 'php', 1, $content);

        $members = $this->helper->extractMembers(
            $document,
            // @phpstan-ignore argument.type (test uses fake class name)
            new ClassName('NonExistent'),
            Visibility::Public,
            MemberFilter::All,
        );

        self::assertSame([], $members);
    }

    public function testGetMemberAccessContextReturnsNullForSelfOutsideClass(): void
    {
        $content = $this->loadFixture('TopLevel/self_outside_class.php');
        $document = new TextDocument('file:///test.php', 'php', 1, $content);
        // Line 1: self::, cursor at position 6
        $result = $this->helper->getMemberAccessContext($document, 1, 6, []);
        self::assertNull($result, 'self:: outside class should return null');
    }

    public function testGetMemberAccessContextReturnsNullForStaticOutsideClass(): void
    {
        $content = $this->loadFixture('TopLevel/static_outside_class.php');
        $document = new TextDocument('file:///test.php', 'php', 1, $content);
        // Line 1: static::, cursor at position 8
        $result = $this->helper->getMemberAccessContext($document, 1, 8, []);
        self::assertNull($result, 'static:: outside class should return null');
    }

    public function testGetMemberAccessContextReturnsNullForParentOutsideClass(): void
    {
        $content = $this->loadFixture('TopLevel/parent_outside_class.php');
        $document = new TextDocument('file:///test.php', 'php', 1, $content);
        // Line 1: parent::, cursor at position 8
        $result = $this->helper->getMemberAccessContext($document, 1, 8, []);
        self::assertNull($result, 'parent:: outside class should return null');
    }

    public function testResolveChainTypeReturnsNullForMethodOnPrimitive(): void
    {
        // When chain resolves to a primitive type, should return null
        // @phpstan-ignore argument.type (test uses fake class name)
        $result = $this->helper->resolveChainType('$this->method()->', 'App\\StringReturn');
        // memberResolver returns null for unknown class, so chain resolution fails before hitting primitive
        self::assertNull($result);
    }

    public function testResolveChainTypeHandlesMethodCallReturningObject(): void
    {
        // withName() returns self - the type system stores this as literal 'self'
        $result = $this->helperWithReflection->resolveChainType(
            '$this->withName()->',
            'Fixtures\\Domain\\User', // @phpstan-ignore argument.type
        );
        self::assertNotNull($result, 'Should resolve chain through method returning object');
        // Return type is stored as 'self' from parsing
        self::assertSame('self', $result->format());
    }

    public function testResolveChainTypeReturnsNullWhenChainContinuesOnPrimitive(): void
    {
        // getId() returns string. Trying to continue chain with ->foo should return null
        // because primitive types have no resolvable class names
        $result = $this->helperWithReflection->resolveChainType(
            '$this->getId()->length->',
            'Fixtures\\Domain\\User', // @phpstan-ignore argument.type
        );
        self::assertNull($result, 'Chain should fail when continuing from primitive');
    }

    public function testResolveChainTypeHandlesPropertyAccess(): void
    {
        // manager is ?User property
        $result = $this->helperWithReflection->resolveChainType(
            '$this->manager->',
            'Fixtures\\Domain\\User', // @phpstan-ignore argument.type
        );
        self::assertNotNull($result, 'Should resolve property in chain');
    }

    public function testResolveChainTypeHandlesMultiStepChain(): void
    {
        // withName() and withAge() both return self
        $result = $this->helperWithReflection->resolveChainType(
            '$this->withName()->withAge()->',
            'Fixtures\\Domain\\User', // @phpstan-ignore argument.type
        );
        self::assertNotNull($result, 'Should resolve multi-step chain');
        self::assertSame('self', $result->format());
    }

    public function testGetMemberAccessContextResolvesFullyQualifiedClassName(): void
    {
        $content = $this->loadFixture('TopLevel/fully_qualified.php');
        $document = new TextDocument('file:///test.php', 'php', 1, $content);
        // Line 2: \SomeGlobalClass::/*|fq_static*/
        // Cursor at position 18 (after ::)
        $result = $this->helper->getMemberAccessContext($document, 2, 18, []);
        self::assertNotNull($result, 'Should resolve FQ class name');
        self::assertSame('SomeGlobalClass', $result->type->format());
    }

    public function testGetMemberAccessContextResolvesPartiallyQualifiedWithAlias(): void
    {
        $content = $this->loadFixture('TopLevel/aliased_partial.php');
        $document = new TextDocument('file:///test.php', 'php', 1, $content);
        // Line 4 has: Alias\SubClass::/*|partial_alias*/
        // Position after :: is column 16
        $result = $this->helper->getMemberAccessContext($document, 4, 16, []);
        self::assertNotNull($result, 'Should resolve partially qualified name');
        self::assertSame('Foo\\Bar\\SubClass', $result->type->format());
    }

    public function testGetMemberAccessContextResolvesNestedGroupUse(): void
    {
        $content = $this->loadFixture('TopLevel/nested_group_use.php');
        $document = new TextDocument('file:///test.php', 'php', 1, $content);
        // Line 4 has: Thing::/*|nested_group*/
        // Position after :: is column 7
        $result = $this->helper->getMemberAccessContext($document, 4, 7, []);
        self::assertNotNull($result, 'Should resolve nested group use');
        self::assertSame('Vendor\\Package\\Sub\\Thing', $result->type->format());
    }

    public function testGetMemberAccessContextResolvesSimpleAliasedUse(): void
    {
        $content = $this->loadFixture('TopLevel/simple_aliased.php');
        $document = new TextDocument('file:///test.php', 'php', 1, $content);
        // Line 4 has: Alias::/*|simple_alias*/
        // Position after :: is column 7
        $result = $this->helper->getMemberAccessContext($document, 4, 7, []);
        self::assertNotNull($result, 'Should resolve simple aliased use');
        self::assertSame('Vendor\\Package\\ClassName', $result->type->format());
    }

    public function testGetMemberAccessContextResolvesGlobalNamespace(): void
    {
        $content = $this->loadFixture('TopLevel/no_ast.php');
        $document = new TextDocument('file:///test.php', 'php', 1, $content);
        // Line 3 has: SomeClass::/*|empty_ast_static*/
        // Position after :: is column 11
        $result = $this->helper->getMemberAccessContext($document, 3, 11, []);
        self::assertNotNull($result, 'Should resolve class in global namespace');
        // No namespace, no use - class name stays as-is
        self::assertSame('SomeClass', $result->type->format());
    }

    public function testFindParameterTypeWithMultilineSignature(): void
    {
        $content = $this->loadFixture('TopLevel/multiline_function.php');
        $document = new TextDocument('file:///test.php', 'php', 1, $content);
        // Line 10 has: $typed->/*|multiline_param*/
        // Variable $typed is declared on line 9: SomeClass $typed
        // Function signature spans lines 6-10
        $result = $this->helper->findParameterType($document, 10, 'typed', []);
        // Should resolve to 'SomeClass' from the multi-line function signature
        self::assertNotNull($result, 'Should find parameter type from multi-line signature');
        self::assertSame('App\\SomeClass', $result->format());
    }

    public function testGetMemberAccessContextWithAstNamespaceLookup(): void
    {
        $content = $this->loadFixture('TopLevel/namespace_unimported.php');
        $document = new TextDocument('file:///test.php', 'php', 1, $content);
        // Parse to get real AST with namespace
        $ast = $this->parser->parse($document);
        self::assertNotNull($ast, 'Fixture should parse successfully');
        // Line 8 (0-indexed) has:         InternalClass::/*|unimported_static*/
        // 8 spaces + InternalClass (13 chars) + :: = cursor at 23
        $result = $this->helper->getMemberAccessContext($document, 8, 23, $ast);
        self::assertNotNull($result, 'Should resolve unimported class with AST namespace');
        // InternalClass should resolve to App\Services\InternalClass
        self::assertSame('App\\Services\\InternalClass', $result->type->format());
    }

    private function loadFixture(string $relativePath): string
    {
        $fullPath = __DIR__ . '/../Fixtures/' . $relativePath;
        $content = file_get_contents($fullPath);
        if ($content === false) {
            throw new \RuntimeException("Failed to load fixture: $fullPath");
        }
        return $content;
    }
}
