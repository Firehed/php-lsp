<?php

declare(strict_types=1);

namespace Firehed\PhpLsp\Tests\Parser;

use Firehed\PhpLsp\Domain\Type;
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

        $return = $this->returnType($method);
        self::assertSame('array', $return->format(), '@return list<T> becomes array');
        self::assertSame('App\\Models\\User', $return->valueType()?->format(), 'element type is fully qualified');
    }

    public function testParamTagIsResolvedThroughImports(): void
    {
        $source = '<?php namespace App; use App\\Models\\User; '
            . 'class Repo { /** @param User $u */ public function save($u) {} }';
        $method = $this->annotateMethod($source);

        $params = $this->paramTypes($method);
        self::assertArrayHasKey('u', $params);
        self::assertSame('App\\Models\\User', $params['u']->format(), 'aliased short name is fully qualified');
    }

    public function testVarTagOnPropertyIsResolved(): void
    {
        $source = '<?php namespace App; use App\\Models\\User; '
            . 'class C { /** @var User */ public $u; }';
        $tree = (new ParserFactory())->createForNewestSupportedVersion()->parse($source) ?? [];
        $annotated = (new TreeAnnotator())->annotate($tree);
        $property = (new NodeFinder())->findFirstInstanceOf($annotated, Stmt\Property::class);
        self::assertNotNull($property);

        $var = $this->tagType($property, 'var');
        self::assertSame('App\\Models\\User', $var->format(), '@var must resolve aliased short names');
    }

    public function testFullyQualifiedNameKeepsItsForm(): void
    {
        $source = '<?php namespace App; '
            . 'class Repo { /** @return \\App\\Models\\User */ public function one() {} }';
        $method = $this->annotateMethod($source);

        $return = $this->returnType($method);
        self::assertSame('App\\Models\\User', $return->format(), 'leading-backslash form drops the backslash');
    }

    public function testPrimitivesArePassedThrough(): void
    {
        $method = $this->annotateMethod(
            '<?php class C { /** @return array<int, string> */ public function m() {} }',
        );

        $return = $this->returnType($method);
        self::assertSame('array', $return->format(), 'array container preserved');
        self::assertSame('string', $return->valueType()?->format(), 'value type is the last generic argument');
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

        $return = $this->returnType($method);
        self::assertSame(
            'array',
            $return->format(),
            'non-empty-list normalizes to array (its type-resolver parent)',
        );
        self::assertSame(
            'App\\Models\\User',
            $return->valueType()?->format(),
            'phpstan-return wins over @return; value type carried',
        );
    }

    private function returnType(Stmt\ClassMethod $method): Type
    {
        return $this->tagType($method, 'return');
    }

    private function tagType(\PhpParser\Node $node, string $key): Type
    {
        $tags = $node->getAttribute('resolvedDocblockTypes');
        self::assertIsArray($tags, 'annotator must attach the resolved tag map');
        self::assertArrayHasKey($key, $tags, "tag @$key must be present");
        self::assertInstanceOf(Type::class, $tags[$key]);
        return $tags[$key];
    }

    /**
     * @return array<string, Type>
     */
    private function paramTypes(\PhpParser\Node $node): array
    {
        $tags = $node->getAttribute('resolvedDocblockTypes');
        self::assertIsArray($tags);
        self::assertArrayHasKey('params', $tags);
        $params = $tags['params'];
        self::assertIsArray($params);
        /** @var array<string, Type> $params */
        return $params;
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
