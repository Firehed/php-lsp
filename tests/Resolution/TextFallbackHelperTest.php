<?php

declare(strict_types=1);

namespace Firehed\PhpLsp\Tests\Resolution;

use Firehed\PhpLsp\Document\TextDocument;
use Firehed\PhpLsp\Domain\ClassName;
use Firehed\PhpLsp\Domain\Visibility;
use Firehed\PhpLsp\Resolution\MemberFilter;
use Firehed\PhpLsp\Repository\ClassRepository;
use Firehed\PhpLsp\Repository\MemberResolver;
use Firehed\PhpLsp\Resolution\TextFallbackHelper;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(TextFallbackHelper::class)]
class TextFallbackHelperTest extends TestCase
{
    private TextFallbackHelper $helper;

    protected function setUp(): void
    {
        // Create a minimal MemberResolver with a stub ClassRepository
        $classRepository = self::createStub(ClassRepository::class);
        $classRepository->method('get')->willReturn(null);
        $this->helper = new TextFallbackHelper(new MemberResolver($classRepository));
    }

    public function testFindEnclosingClassFromContentReturnsNullForCodeOutsideClass(): void
    {
        $content = "<?php\n\$this->method();\n";
        $result = $this->helper->findEnclosingClassFromContent($content, 1);
        self::assertNull($result);
    }

    public function testFindEnclosingClassFromContentFindsClassWithNamespace(): void
    {
        $content = "<?php\nnamespace App;\nclass Foo {\n    public function test() {}\n}";
        $result = $this->helper->findEnclosingClassFromContent($content, 3);
        self::assertSame('App\\Foo', $result);
    }

    public function testFindEnclosingClassFromContentFindsClassWithoutNamespace(): void
    {
        $content = "<?php\nclass GlobalClass {\n    public function test() {}\n}";
        $result = $this->helper->findEnclosingClassFromContent($content, 2);
        self::assertSame('GlobalClass', $result);
    }

    public function testFindNamespaceReturnsNullWhenNoNamespace(): void
    {
        $lines = ['<?php', '', 'class Foo {}'];
        $result = $this->helper->findNamespace($lines, 2);
        self::assertNull($result);
    }

    public function testFindNamespaceFindsNamespaceWithSemicolon(): void
    {
        $lines = ['<?php', 'namespace App\\Services;', '', 'class Foo {}'];
        $result = $this->helper->findNamespace($lines, 3);
        self::assertSame('App\\Services', $result);
    }

    public function testFindNamespaceFindsNamespaceWithBrace(): void
    {
        $lines = ['<?php', 'namespace App\\Services {', '', 'class Foo {}'];
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
        $content = "<?php\nclass Foo {}\n";
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
}
