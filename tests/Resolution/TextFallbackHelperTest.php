<?php

declare(strict_types=1);

namespace Firehed\PhpLsp\Tests\Resolution;

use Firehed\PhpLsp\Document\TextDocument;
use Firehed\PhpLsp\Domain\ClassInfo;
use Firehed\PhpLsp\Domain\ClassName;
use Firehed\PhpLsp\Domain\MemberFilter;
use Firehed\PhpLsp\Domain\Visibility;
use Firehed\PhpLsp\Index\ComposerAutoloadMap;
use Firehed\PhpLsp\Knowledge\KnowledgeStack;
use Firehed\PhpLsp\Knowledge\SymbolSource;
use Firehed\PhpLsp\Parser\ParserService;
use Firehed\PhpLsp\Repository\MemberResolver;
use Firehed\PhpLsp\Resolution\ResolvedMember;
use Firehed\PhpLsp\Resolution\TextFallbackHelper;
use Firehed\PhpLsp\Tests\LoadsFixturesTrait;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(TextFallbackHelper::class)]
class TextFallbackHelperTest extends TestCase
{
    use LoadsFixturesTrait;

    private TextFallbackHelper $helper;
    private TextFallbackHelper $helperWithReflection;
    private ParserService $parser;

    protected function setUp(): void
    {
        // A minimal MemberResolver whose source resolves nothing.
        $source = self::createStub(SymbolSource::class);
        $source->method('lookupClassLike')->willReturn(null);
        $this->helper = new TextFallbackHelper(new MemberResolver($source));

        // A helper with fixture-based class resolution for inheritance-chain tests.
        $this->parser = new ParserService();
        $fixturesRoot = __DIR__ . '/../Fixtures';
        $knowledge = KnowledgeStack::forProject(
            ComposerAutoloadMap::fromProjectRoot($fixturesRoot),
            $fixturesRoot . '/vendor',
            $this->parser,
        );
        $this->helperWithReflection = new TextFallbackHelper(new MemberResolver($knowledge->source));
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

    public function testNameContextFromTextParsesGroupUseWithAndWithoutAlias(): void
    {
        $lines = [
            '<?php',
            'namespace App;',
            'use Vendor\\Pkg\\{Plain, Nested\\Deep, Aliased as Alias};',
            '',
        ];
        $context = TextFallbackHelper::nameContextFromText($lines, 3);

        self::assertSame('App', $context->namespace);
        self::assertSame('Vendor\\Pkg\\Plain', $context->classImports['Plain']);
        self::assertSame('Vendor\\Pkg\\Nested\\Deep', $context->classImports['Deep']);
        self::assertSame('Vendor\\Pkg\\Aliased', $context->classImports['Alias']);
    }

    public function testNameContextFromTextParsesSimpleUseWithAlias(): void
    {
        $lines = [
            '<?php',
            'use Vendor\\Pkg\\Something as Thing;',
            '',
        ];
        $context = TextFallbackHelper::nameContextFromText($lines, 2);

        self::assertSame('Vendor\\Pkg\\Something', $context->classImports['Thing']);
    }

    public function testMatchParameterTypeAccumulatesMultilineSignature(): void
    {
        $content = $this->loadFixture('TopLevel/multiline_function.php');
        $lines = explode("\n", $content);
        // $typed sits on line 10; its declaration begins on line 5 (function longSignature(),
        // spans four lines to the closing paren on line 9.
        $result = $this->helper->matchParameterType($lines, 10, 'typed');
        self::assertSame('SomeClass', $result);
    }

    public function testMatchParameterTypeReturnsNullWhenNoFunctionDeclarationFound(): void
    {
        $lines = ['<?php', '$name = "top-level";'];
        $result = $this->helper->matchParameterType($lines, 1, 'name');
        self::assertNull($result, 'Scanning code with no function declaration must yield no type');
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

    public function testExtractMembersKeepsAMethodAndAPropertyOfTheSameName(): void
    {
        $content = $this->loadFixture('TopLevel/method_and_property_same_name.php');
        $document = new TextDocument('file:///test.php', 'php', 1, $content);

        $members = $this->helperWithReflection->extractMembers(
            $document,
            // @phpstan-ignore argument.type (test uses fake child class name)
            new ClassName('Test\\MethodAndPropertySameName'),
            Visibility::Private,
            MemberFilter::Instance,
        );

        $names = self::memberNames($members);
        $occurrences = count(array_filter($names, static fn (string $name): bool => $name === 'active'));
        self::assertSame(
            2,
            $occurrences,
            'A method and an inherited property of the same name are two members, not one',
        );
    }

    public function testExtractMembersDeduplicatesCaseVariedOverride(): void
    {
        $content = $this->loadFixture('TopLevel/case_varied_override_child.php');
        $document = new TextDocument('file:///test.php', 'php', 1, $content);

        $members = $this->helperWithReflection->extractMembers(
            $document,
            // @phpstan-ignore argument.type (test uses fake child class name)
            new ClassName('Test\\CaseVariedOverrideChild'),
            Visibility::Private,
            MemberFilter::Instance,
        );

        $names = self::memberNames($members);
        $occurrences = count(array_filter(
            $names,
            static fn (string $name): bool => strtolower($name) === 'overriddenmethod',
        ));
        self::assertSame(
            1,
            $occurrences,
            'Method names are case-insensitive in PHP, so an override spelled differently is still one method',
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
