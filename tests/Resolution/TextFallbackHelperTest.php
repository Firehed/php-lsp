<?php

declare(strict_types=1);

namespace Firehed\PhpLsp\Tests\Resolution;

use Firehed\PhpLsp\Resolution\TextFallbackHelper;
use Firehed\PhpLsp\Tests\LoadsFixturesTrait;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(TextFallbackHelper::class)]
class TextFallbackHelperTest extends TestCase
{
    use LoadsFixturesTrait;

    private TextFallbackHelper $helper;

    protected function setUp(): void
    {
        $this->helper = new TextFallbackHelper();
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

    public function testFindEnclosingClassFromContentFindsInterface(): void
    {
        $content = $this->loadFixture('TopLevel/interface_body.php');
        // Line 6 (0-based) is inside interface MyInterface in namespace App
        $result = $this->helper->findEnclosingClassFromContent($content, 6);
        self::assertSame('App\\MyInterface', $result, 'Interface should be recognized as an enclosing class');
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
