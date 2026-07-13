<?php

declare(strict_types=1);

namespace Firehed\PhpLsp\Tests\Repository;

use Firehed\PhpLsp\Domain\ClassName;
use Firehed\PhpLsp\Domain\Visibility;
use Firehed\PhpLsp\Index\ComposerClassLocator;
use Firehed\PhpLsp\Parser\ParserService;
use Firehed\PhpLsp\Repository\DefaultClassInfoFactory;
use Firehed\PhpLsp\Repository\DefaultClassRepository;
use Firehed\PhpLsp\Repository\MemberResolver;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionProperty;

/**
 * The members reported for a type must match the members PHP actually exposes
 * at runtime, for every shape of the type graph: extends, implements, interface
 * extends interface, trait using trait, and interfaces reached via a parent.
 *
 * Uses live reflection of the fixture classes as the oracle, so a traversal that
 * misses an edge cannot pass by agreeing with a hand-written expectation.
 */
#[CoversClass(MemberResolver::class)]
final class TypeGraphParityTest extends TestCase
{
    private MemberResolver $resolver;

    public static function setUpBeforeClass(): void
    {
        // Fixtures are a separate Composer project; load it so the oracle can
        // reflect on the same classes the resolver reads from disk.
        require_once dirname(__DIR__) . '/Fixtures/vendor/autoload.php';
    }

    /**
     * @return array<string, array{class-string}>
     * @codeCoverageIgnore
     */
    public static function hierarchyTypes(): array
    {
        // @phpstan-ignore return.type (fixture classes are not analyzed)
        return [
            'interface' => ['Fixtures\Hierarchy\BaseInterface'],
            'interface extends interface' => ['Fixtures\Hierarchy\MiddleInterface'],
            'interface extends several, incl. built-in' => ['Fixtures\Hierarchy\LeafInterface'],
            'trait using a trait' => ['Fixtures\Hierarchy\OuterTrait'],
            'abstract class implementing an interface' => ['Fixtures\Hierarchy\AbstractImplementor'],
            'class reaching an interface via its parent' => ['Fixtures\Hierarchy\ConcreteDescendant'],
            'class extending a class, several levels' => ['Fixtures\Hierarchy\GrandchildDescendant'],
            'class using a trait' => ['Fixtures\Traits\ConcreteService'],
            'interface extending a built-in' => ['Fixtures\Repository\Repository'],
            'PSR-7 request' => ['Psr\Http\Message\RequestInterface'],
            'PSR-7 server request' => ['Psr\Http\Message\ServerRequestInterface'],
        ];
    }

    protected function setUp(): void
    {
        $parser = new ParserService();
        $repository = new DefaultClassRepository(
            new DefaultClassInfoFactory(),
            new ComposerClassLocator(dirname(__DIR__) . '/Fixtures'),
            $parser,
        );
        $this->resolver = new MemberResolver($repository);
    }

    /**
     * @param class-string $fqcn
     */
    #[DataProvider('hierarchyTypes')]
    public function testPublicMethodsMatchRuntime(string $fqcn): void
    {
        $resolved = array_map(
            fn ($method) => $method->name->name,
            $this->resolver->getMethods(new ClassName($fqcn), Visibility::Public),
        );

        self::assertSame(
            self::normalize(get_class_methods($fqcn)),
            self::normalize($resolved),
            'resolved public methods should match the methods available at runtime',
        );
    }

    /**
     * @param class-string $fqcn
     */
    #[DataProvider('hierarchyTypes')]
    public function testPublicPropertiesMatchRuntime(string $fqcn): void
    {
        $expected = array_map(
            fn (ReflectionProperty $property) => $property->getName(),
            (new ReflectionClass($fqcn))->getProperties(ReflectionProperty::IS_PUBLIC),
        );

        $resolved = array_map(
            fn ($property) => $property->name->name,
            $this->resolver->getProperties(new ClassName($fqcn), Visibility::Public),
        );

        self::assertSame(
            self::normalize($expected),
            self::normalize($resolved),
            'resolved public properties should match the properties available at runtime',
        );
    }

    /**
     * @param class-string $fqcn
     */
    #[DataProvider('hierarchyTypes')]
    public function testPublicConstantsMatchRuntime(string $fqcn): void
    {
        $expected = [];
        foreach ((new ReflectionClass($fqcn))->getReflectionConstants() as $constant) {
            if ($constant->isPublic()) {
                $expected[] = $constant->getName();
            }
        }

        $resolved = array_map(
            fn ($constant) => $constant->name->name,
            $this->resolver->getConstants(new ClassName($fqcn), Visibility::Public),
        );

        self::assertSame(
            self::normalize($expected),
            self::normalize($resolved),
            'resolved public constants should match the constants available at runtime',
        );
    }

    /**
     * Neither source is ordered, and PHP method names are case-insensitive.
     *
     * @param list<string> $names
     * @return list<string>
     */
    private static function normalize(array $names): array
    {
        $lowered = array_map(strtolower(...), $names);
        sort($lowered);

        return $lowered;
    }
}
