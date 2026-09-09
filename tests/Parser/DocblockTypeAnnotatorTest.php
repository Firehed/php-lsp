<?php

declare(strict_types=1);

namespace Firehed\PhpLsp\Tests\Parser;

use Firehed\PhpLsp\Parser\DocblockTypeAnnotator;
use PhpParser\Node;
use PhpParser\Node\Stmt\ClassMethod;
use PhpParser\Node\Stmt\Function_;
use PhpParser\Node\Stmt\Property;
use PhpParser\NodeFinder;
use PhpParser\NodeTraverser;
use PhpParser\NodeVisitor\NameResolver;
use PhpParser\ParserFactory;
use PHPStan\PhpDocParser\Ast\Type\ArrayTypeNode;
use PHPStan\PhpDocParser\Ast\Type\GenericTypeNode;
use PHPStan\PhpDocParser\Ast\Type\IdentifierTypeNode;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(DocblockTypeAnnotator::class)]
final class DocblockTypeAnnotatorTest extends TestCase
{
    public function testAnnotatesReturnTagOnClassMethodWithResolvedName(): void
    {
        $method = $this->annotateAndFind(
            <<<'PHP'
            <?php
            namespace App;
            use App\Domain\User;
            class Repo {
                /** @return list<User> */
                public function all() {}
            }
            PHP,
            ClassMethod::class,
        );

        $attr = $method->getAttribute('resolvedDocblockTypes');
        self::assertIsArray($attr);
        $return = $attr['return'] ?? null;
        self::assertInstanceOf(GenericTypeNode::class, $return);
        self::assertSame('list', $return->type->name);
        $valueType = $return->genericTypes[0];
        self::assertInstanceOf(IdentifierTypeNode::class, $valueType);
        self::assertSame(
            'App\\Domain\\User',
            $valueType->name,
            'the identifier inside list<User> must be rewritten to its fully qualified name',
        );
    }

    public function testAnnotatesParamTagsKeyedByParameterName(): void
    {
        $method = $this->annotateAndFind(
            <<<'PHP'
            <?php
            namespace App;
            use App\Domain\User;
            class Repo {
                /**
                 * @param User $user
                 * @param list<User> $extras
                 */
                public function save($user, $extras) {}
            }
            PHP,
            ClassMethod::class,
        );

        $attr = $method->getAttribute('resolvedDocblockTypes');
        self::assertIsArray($attr);

        $user = $attr['param:user'] ?? null;
        self::assertInstanceOf(IdentifierTypeNode::class, $user);
        self::assertSame('App\\Domain\\User', $user->name);

        $extras = $attr['param:extras'] ?? null;
        self::assertInstanceOf(GenericTypeNode::class, $extras);
        $valueType = $extras->genericTypes[0];
        self::assertInstanceOf(IdentifierTypeNode::class, $valueType);
        self::assertSame('App\\Domain\\User', $valueType->name);
    }

    public function testAnnotatesVarTagOnProperty(): void
    {
        $property = $this->annotateAndFind(
            <<<'PHP'
            <?php
            namespace App;
            use App\Domain\User;
            class Repo {
                /** @var User[] */
                private array $users;
            }
            PHP,
            Property::class,
        );

        $attr = $property->getAttribute('resolvedDocblockTypes');
        self::assertIsArray($attr);
        $var = $attr['var'] ?? null;
        self::assertInstanceOf(ArrayTypeNode::class, $var);
        $inner = $var->type;
        self::assertInstanceOf(IdentifierTypeNode::class, $inner);
        self::assertSame('App\\Domain\\User', $inner->name);
    }

    public function testAnnotatesFunctionReturnTag(): void
    {
        $function = $this->annotateAndFind(
            <<<'PHP'
            <?php
            namespace App;
            use App\Domain\User;
            /** @return User */
            function loadOne() {}
            PHP,
            Function_::class,
        );

        $attr = $function->getAttribute('resolvedDocblockTypes');
        self::assertIsArray($attr);
        $return = $attr['return'] ?? null;
        self::assertInstanceOf(IdentifierTypeNode::class, $return);
        self::assertSame('App\\Domain\\User', $return->name);
    }

    public function testLeavesPrimitiveKeywordsAlone(): void
    {
        $method = $this->annotateAndFind(
            <<<'PHP'
            <?php
            namespace App;
            class Repo {
                /** @return array<int, string> */
                public function names() {}
            }
            PHP,
            ClassMethod::class,
        );

        $attr = $method->getAttribute('resolvedDocblockTypes');
        self::assertIsArray($attr);
        $return = $attr['return'] ?? null;
        self::assertInstanceOf(GenericTypeNode::class, $return);
        self::assertSame('array', $return->type->name, '`array` is a keyword, not a class-like');
        $key = $return->genericTypes[0];
        self::assertInstanceOf(IdentifierTypeNode::class, $key);
        self::assertSame('int', $key->name);
        $value = $return->genericTypes[1];
        self::assertInstanceOf(IdentifierTypeNode::class, $value);
        self::assertSame('string', $value->name);
    }

    public function testPsalmAndPhpstanTagsAreRead(): void
    {
        $method = $this->annotateAndFind(
            <<<'PHP'
            <?php
            namespace App;
            use App\Domain\User;
            class Repo {
                /** @phpstan-return list<User> */
                public function all() {}
            }
            PHP,
            ClassMethod::class,
        );

        $attr = $method->getAttribute('resolvedDocblockTypes');
        self::assertIsArray($attr);
        self::assertArrayHasKey('return', $attr, 'a @phpstan-return tag registers as a return type');
    }

    public function testNodeWithoutDocblockGetsNoAttribute(): void
    {
        $method = $this->annotateAndFind(
            <<<'PHP'
            <?php
            namespace App;
            class Repo {
                public function all() {}
            }
            PHP,
            ClassMethod::class,
        );

        self::assertNull(
            $method->getAttribute('resolvedDocblockTypes'),
            'a node without a docblock stays free of the attribute',
        );
    }

    /**
     * @template T of Node
     * @param class-string<T> $nodeClass
     * @return T
     */
    private function annotateAndFind(string $source, string $nodeClass): Node
    {
        $tree = (new ParserFactory())->createForNewestSupportedVersion()->parse($source);
        self::assertIsArray($tree);
        $resolver = new NameResolver();
        $traverser = new NodeTraverser();
        $traverser->addVisitor($resolver);
        $traverser->addVisitor(new DocblockTypeAnnotator($resolver->getNameContext()));
        $annotated = $traverser->traverse($tree);

        $found = (new NodeFinder())->findFirstInstanceOf($annotated, $nodeClass);
        self::assertInstanceOf($nodeClass, $found);
        return $found;
    }
}
