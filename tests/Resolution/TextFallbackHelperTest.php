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
use Firehed\PhpLsp\Resolution\ResolvedMember;
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

    public function testGetMemberAccessContextResolvesGlobalNamespaceImport(): void
    {
        // When inside a namespace with `use GlobalClass;`, the import should
        // resolve to GlobalClass (not App\GlobalClass)
        $content = $this->loadFixture('TopLevel/global_namespace_use_with_ns.php');
        $document = new TextDocument('file:///test.php', 'php', 1, $content);
        // Line 8 has: GlobalClass::/*|global_ns_use*/
        // Position after :: is column 13
        $result = $this->helper->getMemberAccessContext($document, 8, 13, []);
        self::assertNotNull($result, 'Should resolve global namespace import');
        // Class should resolve to GlobalClass, NOT App\GlobalClass
        self::assertSame(
            'GlobalClass',
            $result->type->format(),
            'Global namespace import should resolve to GlobalClass, not App\\GlobalClass',
        );
    }

    public function testGetMemberAccessContextResolvesAliasedGroupUse(): void
    {
        // Test aliased item within group use: use Vendor\Package\{Something as Alias}
        $content = $this->loadFixture('TopLevel/aliased_group_use.php');
        $document = new TextDocument('file:///test.php', 'php', 1, $content);
        // Line 6 has: Alias::/*|aliased_group*/
        // Position after :: is column 7
        $result = $this->helper->getMemberAccessContext($document, 6, 7, []);
        self::assertNotNull($result, 'Should resolve aliased group use');
        self::assertSame(
            'Vendor\\Package\\Something',
            $result->type->format(),
            'Aliased group use should resolve to full FQN',
        );
    }

    public function testExtractMembersIncludesInstanceMembersNamedStatic(): void
    {
        $content = $this->loadFixture('TopLevel/static_named_members.php');
        $document = new TextDocument('file:///test.php', 'php', 1, $content);

        $members = $this->helper->extractMembers(
            $document,
            // @phpstan-ignore argument.type (test uses global-namespace fake class name)
            new ClassName('StaticNamed'),
            Visibility::Private,
            MemberFilter::Instance,
        );

        $names = self::memberNames($members);
        self::assertContains(
            'staticFactory',
            $names,
            'Instance method named staticFactory must not be misclassified as static',
        );
        self::assertContains(
            'staticCache',
            $names,
            'Instance property named staticCache must not be misclassified as static',
        );
        self::assertNotContains(
            'realStatic',
            $names,
            'A genuinely static method must be excluded from the instance filter',
        );
    }

    public function testExtractMembersDoesNotLeakAcrossClassesInSameFile(): void
    {
        $content = $this->loadFixture('TopLevel/two_classes.php');
        $document = new TextDocument('file:///test.php', 'php', 1, $content);

        $members = $this->helper->extractMembers(
            $document,
            // @phpstan-ignore argument.type (test uses global-namespace fake class name)
            new ClassName('FirstClass'),
            Visibility::Private,
            MemberFilter::All,
        );

        $names = self::memberNames($members);
        self::assertContains('firstMethod', $names, 'The target class own member should be present');
        self::assertNotContains(
            'secondMethod',
            $names,
            'Members of a later class in the same file must not leak into extraction',
        );
        self::assertNotContains(
            'secretSecond',
            $names,
            'Private members of a later class must not leak into extraction',
        );
    }

    public function testExtractMembersExcludesInaccessibleMethodsByVisibility(): void
    {
        $content = $this->loadFixture('TopLevel/two_classes.php');
        $document = new TextDocument('file:///test.php', 'php', 1, $content);

        // Public access level: only public members of the class body are reachable
        $members = $this->helper->extractMembers(
            $document,
            // @phpstan-ignore argument.type (test uses global-namespace fake class name)
            new ClassName('FirstClass'),
            Visibility::Public,
            MemberFilter::Instance,
        );

        $names = self::memberNames($members);
        self::assertContains('firstMethod', $names, 'Public methods should be reachable from a public access level');
        self::assertNotContains(
            'firstPrivate',
            $names,
            'A private method must be filtered out when only public access is permitted',
        );
    }

    public function testExtractMembersHandlesUnclosedClassBody(): void
    {
        // Incomplete code: the class body has no closing brace yet
        $content = $this->loadFixture('TopLevel/unclosed_class.php');
        $document = new TextDocument('file:///test.php', 'php', 1, $content);

        $members = $this->helper->extractMembers(
            $document,
            // @phpstan-ignore argument.type (test uses global-namespace fake class name)
            new ClassName('Unclosed'),
            Visibility::Private,
            MemberFilter::All,
        );

        $names = self::memberNames($members);
        self::assertContains('unclosedMethod', $names, 'Members of an unclosed class body should still be extracted');
    }

    public function testFindEnclosingClassFromContentFindsInterface(): void
    {
        $content = $this->loadFixture('TopLevel/interface_body.php');
        // Line 6 (0-based) is inside interface MyInterface in namespace App
        $result = $this->helper->findEnclosingClassFromContent($content, 6);
        self::assertSame('App\\MyInterface', $result, 'Interface should be recognized as an enclosing class');
    }

    public function testExtractMembersFindsInterfaceConstants(): void
    {
        $content = $this->loadFixture('TopLevel/interface_body.php');
        $document = new TextDocument('file:///test.php', 'php', 1, $content);

        $members = $this->helper->extractMembers(
            $document,
            // @phpstan-ignore argument.type (test uses fake class name)
            new ClassName('App\\MyInterface'),
            Visibility::Public,
            MemberFilter::Static,
        );

        $names = self::memberNames($members);
        self::assertContains('FOO', $names, 'Interface constants should be extracted for static access');
    }

    public function testFindParameterTypeResolvesUnionType(): void
    {
        $content = $this->loadFixture('TopLevel/union_param.php');
        $document = new TextDocument('file:///test.php', 'php', 1, $content);
        // Line 8 (0-based) is `$thing->`; the parameter is declared on line 6 as `Foo|Bar $thing`
        $result = $this->helper->findParameterType($document, 8, 'thing', []);

        self::assertNotNull($result, 'Union-typed parameter should resolve to a type');
        $fqns = array_map(
            static fn (ClassName $className): string => $className->fqn,
            $result->getResolvableClassNames(),
        );
        self::assertContains('App\\Foo', $fqns, 'Union type must include its first member');
        self::assertContains('App\\Bar', $fqns, 'Union type must include its second member');
    }

    public function testFindParameterTypeReturnsNullForPrimitiveType(): void
    {
        $content = $this->loadFixture('TopLevel/primitive_param.php');
        $document = new TextDocument('file:///test.php', 'php', 1, $content);
        // Line 8 (0-based) is `$value->`; the parameter is declared as `string $value`
        $result = $this->helper->findParameterType($document, 8, 'value', []);

        self::assertNull($result, 'A primitive-typed parameter has no members and should resolve to null');
    }

    public function testExtractMembersExcludesInheritedPrivateMembers(): void
    {
        $content = $this->loadFixture('TopLevel/inherited_child.php');
        $document = new TextDocument('file:///test.php', 'php', 1, $content);

        $members = $this->helperWithReflection->extractMembers(
            $document,
            // @phpstan-ignore argument.type (test uses fake child class name)
            new ClassName('Test\\InheritedChild'),
            Visibility::Private,
            MemberFilter::Instance,
        );

        $names = self::memberNames($members);
        self::assertContains('parentMethod', $names, 'Public inherited members should be available');
        self::assertContains('protectedMethod', $names, 'Protected inherited members should be available');
        self::assertNotContains(
            'privateMethod',
            $names,
            'A parent private method is not accessible from a child and must not be offered',
        );
        self::assertNotContains(
            'privateProperty',
            $names,
            'A parent private property is not accessible from a child and must not be offered',
        );
    }

    public function testExtractMembersDeduplicatesOverriddenMembers(): void
    {
        $content = $this->loadFixture('TopLevel/inherited_child.php');
        $document = new TextDocument('file:///test.php', 'php', 1, $content);

        $members = $this->helperWithReflection->extractMembers(
            $document,
            // @phpstan-ignore argument.type (test uses fake child class name)
            new ClassName('Test\\InheritedChild'),
            Visibility::Private,
            MemberFilter::Instance,
        );

        $names = self::memberNames($members);
        $occurrences = count(array_filter($names, static fn (string $name): bool => $name === 'overriddenMethod'));
        self::assertSame(
            1,
            $occurrences,
            'An overridden method must appear once, not duplicated across the child and its parent',
        );
    }

    /**
     * @param list<ResolvedMember> $members
     * @return list<string>
     */
    private static function memberNames(array $members): array
    {
        return array_map(
            static fn (ResolvedMember $member): string => $member->getName()->name,
            $members,
        );
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
