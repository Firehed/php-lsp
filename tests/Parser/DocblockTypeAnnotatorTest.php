<?php

declare(strict_types=1);

namespace Firehed\PhpLsp\Tests\Parser;

use Firehed\PhpLsp\Parser\DocblockTypeAnnotator;
use Firehed\PhpLsp\Parser\TreeAnnotator;
use PhpParser\Node\Stmt;
use PhpParser\NodeFinder;
use PhpParser\ParserFactory;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(DocblockTypeAnnotator::class)]
final class DocblockTypeAnnotatorTest extends TestCase
{
    public function testReturnTagIsResolvedThroughImports(): void
    {
        $source = '<?php namespace App; use App\\Models\\User; '
            . 'class Repo { /** @return list<User> */ public function all() {} }';
        $method = $this->annotateMethod($source);

        $tags = $method->getAttribute('resolvedDocblockTypes');
        self::assertIsArray($tags, 'annotator must attach the resolved tag map');
        self::assertSame(
            'list<App\\Models\\User>',
            $tags['return'] ?? null,
            'aliased class name in @return must be fully qualified',
        );
    }

    public function testParamTagIsResolvedThroughImports(): void
    {
        $source = '<?php namespace App; use App\\Models\\User; '
            . 'class Repo { /** @param User $u */ public function save($u) {} }';
        $method = $this->annotateMethod($source);

        $tags = $method->getAttribute('resolvedDocblockTypes');
        self::assertIsArray($tags);
        self::assertSame(
            ['u' => 'App\\Models\\User'],
            $tags['params'] ?? null,
            'aliased class name in @param must be fully qualified',
        );
    }

    public function testVarTagOnPropertyIsResolved(): void
    {
        $source = '<?php namespace App; use App\\Models\\User; '
            . 'class C { /** @var User */ public $u; }';
        $tree = (new ParserFactory())->createForNewestSupportedVersion()->parse($source) ?? [];
        $annotated = (new TreeAnnotator())->annotate($tree);
        $property = (new NodeFinder())->findFirstInstanceOf($annotated, Stmt\Property::class);
        self::assertNotNull($property);

        $tags = $property->getAttribute('resolvedDocblockTypes');
        self::assertIsArray($tags);
        self::assertSame('App\\Models\\User', $tags['var'] ?? null, '@var must resolve aliased short names');
    }

    public function testFullyQualifiedNameKeepsItsForm(): void
    {
        $source = '<?php namespace App; '
            . 'class Repo { /** @return \\App\\Models\\User */ public function one() {} }';
        $method = $this->annotateMethod($source);

        $tags = $method->getAttribute('resolvedDocblockTypes');
        self::assertIsArray($tags);
        self::assertSame('App\\Models\\User', $tags['return'] ?? null, 'leading-backslash form drops the backslash');
    }

    public function testPrimitivesArePassedThrough(): void
    {
        $method = $this->annotateMethod(
            '<?php class C { /** @return array<int, string> */ public function m() {} }',
        );

        $tags = $method->getAttribute('resolvedDocblockTypes');
        self::assertIsArray($tags);
        self::assertSame(
            'array<int, string>',
            $tags['return'] ?? null,
            'array and int and string are keywords, not classes',
        );
    }

    public function testMethodWithoutTagsGetsNoAttribute(): void
    {
        $method = $this->annotateMethod(
            '<?php class C { /** Just a description */ public function m() {} }',
        );

        self::assertNull($method->getAttribute('resolvedDocblockTypes'), 'a description without tags stores nothing');
    }

    public function testPhpstanReturnOverridesNativeReturn(): void
    {
        $method = $this->annotateMethod(
            '<?php namespace App; use App\\Models\\User; class C {
                /**
                 * @return list<User>
                 * @phpstan-return non-empty-list<User>
                 */
                public function m() {}
            }',
        );

        $tags = $method->getAttribute('resolvedDocblockTypes');
        self::assertIsArray($tags);
        self::assertSame(
            'non-empty-list<App\\Models\\User>',
            $tags['return'] ?? null,
            'phpstan-return wins over @return',
        );
    }

    private function annotateMethod(string $source): Stmt\ClassMethod
    {
        $tree = (new ParserFactory())->createForNewestSupportedVersion()->parse($source) ?? [];
        $annotated = (new TreeAnnotator())->annotate($tree);
        $method = (new NodeFinder())->findFirstInstanceOf($annotated, Stmt\ClassMethod::class);
        self::assertNotNull($method, 'source must contain a method');
        return $method;
    }
}
