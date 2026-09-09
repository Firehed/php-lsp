<?php

declare(strict_types=1);

namespace Firehed\PhpLsp\Tests\Domain;

use Firehed\PhpLsp\Domain\Type;
use Firehed\PhpLsp\Domain\TypeFactory;
use PHPStan\PhpDocParser\Ast\Type\ArrayShapeItemNode;
use PHPStan\PhpDocParser\Ast\Type\ArrayShapeNode;
use PHPStan\PhpDocParser\Ast\Type\ArrayTypeNode;
use PHPStan\PhpDocParser\Ast\Type\CallableTypeNode;
use PHPStan\PhpDocParser\Ast\Type\ConditionalTypeForParameterNode;
use PHPStan\PhpDocParser\Ast\Type\ConditionalTypeNode;
use PHPStan\PhpDocParser\Ast\Type\ConstTypeNode;
use PHPStan\PhpDocParser\Ast\Type\GenericTypeNode;
use PHPStan\PhpDocParser\Ast\Type\IdentifierTypeNode;
use PHPStan\PhpDocParser\Ast\Type\IntersectionTypeNode;
use PHPStan\PhpDocParser\Ast\Type\InvalidTypeNode;
use PHPStan\PhpDocParser\Ast\Type\NullableTypeNode;
use PHPStan\PhpDocParser\Ast\Type\ObjectShapeNode;
use PHPStan\PhpDocParser\Ast\Type\OffsetAccessTypeNode;
use PHPStan\PhpDocParser\Ast\Type\ThisTypeNode;
use PHPStan\PhpDocParser\Ast\Type\TypeNode;
use PHPStan\PhpDocParser\Ast\Type\UnionTypeNode;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

/**
 * Every concrete TypeNode subclass shipped by phpstan/phpdoc-parser must be
 * accounted for by {@see TypeFactory::fromDocblockType} — either mapped to a
 * `Domain\Type` (SUPPORTED) or explicitly rejected (UNSUPPORTED). A new
 * subclass added by the library will fail {@see testEveryTypeNodeIsCategorized}
 * so a human decides which bucket it belongs to, rather than silently becoming
 * `null` and dropping types on the floor.
 */
#[CoversClass(TypeFactory::class)]
final class DocblockTypeCoverageGridTest extends TestCase
{
    /** @var list<class-string<TypeNode>> */
    private const array SUPPORTED = [
        ArrayTypeNode::class,
        GenericTypeNode::class,
        IdentifierTypeNode::class,
        IntersectionTypeNode::class,
        NullableTypeNode::class,
        UnionTypeNode::class,
    ];

    /** @var list<class-string<TypeNode>> */
    private const array UNSUPPORTED = [
        ArrayShapeNode::class,
        CallableTypeNode::class,
        ConditionalTypeForParameterNode::class,
        ConditionalTypeNode::class,
        ConstTypeNode::class,
        InvalidTypeNode::class,
        ObjectShapeNode::class,
        OffsetAccessTypeNode::class,
        ThisTypeNode::class,
    ];

    public function testEveryTypeNodeIsCategorized(): void
    {
        $known = [...self::SUPPORTED, ...self::UNSUPPORTED];
        sort($known);

        $actual = self::discoverConcreteTypeNodes();
        sort($actual);

        self::assertSame(
            $known,
            $actual,
            'A concrete TypeNode subclass exists that is neither SUPPORTED nor '
                . 'UNSUPPORTED. Add it to whichever list matches how '
                . 'TypeFactory::fromDocblockType handles it. Silently falling to '
                . 'null is the failure mode this grid exists to prevent.',
        );
    }

    /**
     * @return iterable<string, array{TypeNode}>
     */
    public static function supportedNodeProvider(): iterable
    {
        yield 'identifier (class)' => [new IdentifierTypeNode('App\\User')];
        yield 'identifier (primitive)' => [new IdentifierTypeNode('int')];
        yield 'nullable' => [new NullableTypeNode(new IdentifierTypeNode('App\\User'))];
        yield 'array shorthand' => [new ArrayTypeNode(new IdentifierTypeNode('App\\User'))];
        yield 'generic array' => [new GenericTypeNode(
            new IdentifierTypeNode('array'),
            [new IdentifierTypeNode('App\\User')],
        )];
        yield 'union' => [new UnionTypeNode([
            new IdentifierTypeNode('int'),
            new IdentifierTypeNode('string'),
        ])];
        yield 'intersection' => [new IntersectionTypeNode([
            new IdentifierTypeNode('App\\A'),
            new IdentifierTypeNode('App\\B'),
        ])];
    }

    #[DataProvider('supportedNodeProvider')]
    public function testSupportedNodesProduceANonNullType(TypeNode $node): void
    {
        $result = TypeFactory::fromDocblockType($node);
        self::assertNotNull($result, 'Every SUPPORTED TypeNode must map to a Domain Type');
        self::assertInstanceOf(Type::class, $result);
    }

    /**
     * @return iterable<string, array{TypeNode}>
     */
    public static function unsupportedNodeProvider(): iterable
    {
        yield 'callable' => [new CallableTypeNode(
            new IdentifierTypeNode('callable'),
            [],
            new IdentifierTypeNode('void'),
            [],
        )];
        yield 'array shape' => [ArrayShapeNode::createSealed([
            new ArrayShapeItemNode(
                new IdentifierTypeNode('key'),
                false,
                new IdentifierTypeNode('string'),
            ),
        ])];
        yield 'this' => [new ThisTypeNode()];
    }

    #[DataProvider('unsupportedNodeProvider')]
    public function testUnsupportedNodesReturnNull(TypeNode $node): void
    {
        self::assertNull(
            TypeFactory::fromDocblockType($node),
            'Every UNSUPPORTED TypeNode must return null so the native type wins',
        );
    }

    public function testSupportedListMatchesTypesThatProduceANonNullResult(): void
    {
        // A class in SUPPORTED that actually returns null would be a lie.
        // The supportedNodeProvider covers every SUPPORTED class shape.
        foreach (self::SUPPORTED as $class) {
            $covered = false;
            foreach (self::supportedNodeProvider() as [$node]) {
                if ($node::class === $class) {
                    $covered = true;
                    break;
                }
            }
            self::assertTrue(
                $covered,
                sprintf('SUPPORTED class %s has no case in supportedNodeProvider', $class),
            );
        }
    }

    public function testUnsupportedListMatchesTypesThatReturnNull(): void
    {
        foreach (self::unsupportedNodeProvider() as [$node]) {
            self::assertContains(
                $node::class,
                self::UNSUPPORTED,
                sprintf(
                    'unsupportedNodeProvider has a case for %s but UNSUPPORTED does not list it',
                    $node::class,
                ),
            );
        }
    }

    /**
     * Discover every concrete `TypeNode` subclass phpstan/phpdoc-parser ships,
     * by scanning the vendor directory. New library releases add new files;
     * this test is the tripwire.
     *
     * @return list<class-string<TypeNode>>
     */
    private static function discoverConcreteTypeNodes(): array
    {
        $dir = dirname(__DIR__, 2) . '/vendor/phpstan/phpdoc-parser/src/Ast/Type';
        $files = glob($dir . '/*.php');
        self::assertNotFalse($files, 'phpstan/phpdoc-parser TypeNode dir must be present');

        $classes = [];
        foreach ($files as $file) {
            $short = basename($file, '.php');
            $class = 'PHPStan\\PhpDocParser\\Ast\\Type\\' . $short;
            if (!class_exists($class)) {
                continue;
            }
            $reflection = new ReflectionClass($class);
            if ($reflection->isAbstract() || $reflection->isInterface()) {
                continue;
            }
            if (!$reflection->implementsInterface(TypeNode::class)) {
                continue;
            }
            /** @var class-string<TypeNode> $class */
            $classes[] = $class;
        }
        return $classes;
    }
}
