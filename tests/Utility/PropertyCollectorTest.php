<?php

declare(strict_types=1);

namespace Firehed\PhpLsp\Tests\Utility;

use Firehed\PhpLsp\Utility\PropertyCollector;
use Firehed\PhpLsp\Utility\PropertyInfo;
use PhpParser\ParserFactory;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(PropertyCollector::class)]
#[CoversClass(PropertyInfo::class)]
final class PropertyCollectorTest extends TestCase
{
    private \PhpParser\Parser $parser;

    protected function setUp(): void
    {
        $this->parser = (new ParserFactory())->createForNewestSupportedVersion();
    }

    public function testCollectsRegularProperty(): void
    {
        $code = <<<'PHP'
<?php
class User
{
    private string $name;
}
PHP;
        $ast = $this->parser->parse($code);
        self::assertNotNull($ast);

        $properties = PropertyCollector::collect($ast[0]);

        self::assertCount(1, $properties);
        self::assertSame('name', $properties[0]->name);
        self::assertTrue($properties[0]->isPrivate);
        self::assertFalse($properties[0]->isStatic);
    }

    public function testCollectsPromotedProperty(): void
    {
        $code = <<<'PHP'
<?php
class User
{
    public function __construct(
        private string $name,
        private int $age,
    ) {}
}
PHP;
        $ast = $this->parser->parse($code);
        self::assertNotNull($ast);

        $properties = PropertyCollector::collect($ast[0]);

        self::assertCount(2, $properties);

        $names = array_map(fn(PropertyInfo $p) => $p->name, $properties);
        self::assertContains('name', $names);
        self::assertContains('age', $names);
    }

    public function testCollectsMixedRegularAndPromotedProperties(): void
    {
        $code = <<<'PHP'
<?php
class User
{
    public string $email;

    public function __construct(
        private string $name,
    ) {}
}
PHP;
        $ast = $this->parser->parse($code);
        self::assertNotNull($ast);

        $properties = PropertyCollector::collect($ast[0]);

        self::assertCount(2, $properties);
        $names = array_map(fn(PropertyInfo $p) => $p->name, $properties);
        self::assertContains('email', $names);
        self::assertContains('name', $names);
    }

    public function testExcludesNonPromotedConstructorParams(): void
    {
        $code = <<<'PHP'
<?php
class User
{
    private string $fullName;

    public function __construct(
        string $firstName,
        string $lastName,
        private int $age,
    ) {
        $this->fullName = $firstName . ' ' . $lastName;
    }
}
PHP;
        $ast = $this->parser->parse($code);
        self::assertNotNull($ast);

        $properties = PropertyCollector::collect($ast[0]);

        self::assertCount(2, $properties);
        $names = array_map(fn(PropertyInfo $p) => $p->name, $properties);
        self::assertContains('fullName', $names);
        self::assertContains('age', $names);
        self::assertNotContains('firstName', $names);
        self::assertNotContains('lastName', $names);
    }

    public function testPropertyInfoContainsTypeNode(): void
    {
        $code = <<<'PHP'
<?php
class User
{
    private string $name;
}
PHP;
        $ast = $this->parser->parse($code);
        self::assertNotNull($ast);

        $properties = PropertyCollector::collect($ast[0]);

        self::assertCount(1, $properties);
        self::assertNotNull($properties[0]->type);
    }

    public function testPropertyInfoContainsLineNumbers(): void
    {
        $code = <<<'PHP'
<?php
class User
{
    private string $name;
}
PHP;
        $ast = $this->parser->parse($code);
        self::assertNotNull($ast);

        $properties = PropertyCollector::collect($ast[0]);

        self::assertCount(1, $properties);
        self::assertSame(4, $properties[0]->startLine);
    }

    public function testPromotedPropertyHasCorrectVisibility(): void
    {
        $code = <<<'PHP'
<?php
class User
{
    public function __construct(
        public string $publicName,
        protected string $protectedName,
        private string $privateName,
    ) {}
}
PHP;
        $ast = $this->parser->parse($code);
        self::assertNotNull($ast);

        $properties = PropertyCollector::collect($ast[0]);

        self::assertCount(3, $properties);

        $byName = [];
        foreach ($properties as $prop) {
            $byName[$prop->name] = $prop;
        }

        self::assertTrue($byName['publicName']->isPublic);
        self::assertTrue($byName['protectedName']->isProtected);
        self::assertTrue($byName['privateName']->isPrivate);
    }

    public function testStaticProperty(): void
    {
        $code = <<<'PHP'
<?php
class Counter
{
    private static int $count = 0;
}
PHP;
        $ast = $this->parser->parse($code);
        self::assertNotNull($ast);

        $properties = PropertyCollector::collect($ast[0]);

        self::assertCount(1, $properties);
        self::assertTrue($properties[0]->isStatic);
    }

    public function testReadonlyProperty(): void
    {
        $code = <<<'PHP'
<?php
class User
{
    public function __construct(
        public readonly string $name,
    ) {}
}
PHP;
        $ast = $this->parser->parse($code);
        self::assertNotNull($ast);

        $properties = PropertyCollector::collect($ast[0]);

        self::assertCount(1, $properties);
        self::assertTrue($properties[0]->isReadonly);
    }

    public function testDocCommentExtraction(): void
    {
        $code = <<<'PHP'
<?php
class User
{
    /** The user's display name */
    private string $name;
}
PHP;
        $ast = $this->parser->parse($code);
        self::assertNotNull($ast);

        $properties = PropertyCollector::collect($ast[0]);

        self::assertCount(1, $properties);
        self::assertNotNull($properties[0]->docComment);
        self::assertStringContainsString('display name', $properties[0]->docComment->getText());
    }

    public function testPromotedPropertyDocComment(): void
    {
        $code = <<<'PHP'
<?php
class User
{
    public function __construct(
        /** The user's display name */
        private string $name,
    ) {}
}
PHP;
        $ast = $this->parser->parse($code);
        self::assertNotNull($ast);

        $properties = PropertyCollector::collect($ast[0]);

        self::assertCount(1, $properties);
        self::assertNotNull($properties[0]->docComment);
        self::assertStringContainsString('display name', $properties[0]->docComment->getText());
    }

    public function testReturnsEmptyForInterface(): void
    {
        $code = <<<'PHP'
<?php
interface UserInterface
{
    public function getName(): string;
}
PHP;
        $ast = $this->parser->parse($code);
        self::assertNotNull($ast);

        $properties = PropertyCollector::collect($ast[0]);

        self::assertCount(0, $properties);
    }

    public function testReturnsEmptyForNull(): void
    {
        $properties = PropertyCollector::collect(null);

        self::assertCount(0, $properties);
    }
}
