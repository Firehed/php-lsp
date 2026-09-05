<?php

declare(strict_types=1);

namespace Firehed\PhpLsp\Tests\Repository;

use Firehed\PhpLsp\Domain\ClassName;
use Firehed\PhpLsp\Domain\Visibility;
use Firehed\PhpLsp\Index\ComposerAutoloadMap;
use Firehed\PhpLsp\Knowledge\KnowledgeStack;
use Firehed\PhpLsp\Repository\MemberResolver;
use Firehed\PhpLsp\Tests\Parser\ProductionSyntaxSource;
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
            'trait insteadof and as adaptations' => ['Fixtures\Hierarchy\TraitAdaptationUser'],
            'trait insteadof with excluded trait walked first' => ['Fixtures\Hierarchy\TraitAdaptationReversedUser'],
            'trait alias whose new name collides with an inherited method'
                => ['Fixtures\Hierarchy\TraitAliasCollidingUser'],
            'trait alias without an explicit source trait' => ['Fixtures\Hierarchy\TraitNamelessAliasUser'],
            'enum implementing interface' => ['Fixtures\Hierarchy\EnumWithInterface'],
        ];
    }

    protected function setUp(): void
    {
        $fixturesRoot = dirname(__DIR__) . '/Fixtures';
        $production = ProductionSyntaxSource::create();
        $knowledge = KnowledgeStack::forProject(
            ComposerAutoloadMap::fromProjectRoot($fixturesRoot),
            $fixturesRoot . '/vendor',
            $production->source,
            $production->reader,
        );
        $this->resolver = new MemberResolver($knowledge->source);
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

        // PHP's reflection treats an enum case as a public constant; the
        // domain here splits ConstantInfo from EnumCaseInfo, so parity is
        // asserted against the union of both.
        $resolved = array_map(
            fn ($constant) => $constant->name->name,
            $this->resolver->getConstants(new ClassName($fqcn), Visibility::Public),
        );
        $resolved = array_merge($resolved, array_map(
            fn ($case) => $case->name->name,
            $this->resolver->getEnumCases(new ClassName($fqcn)),
        ));

        self::assertSame(
            self::normalize($expected),
            self::normalize($resolved),
            'resolved public constants should match the constants available at runtime',
        );
    }

    /**
     * @return array<string, array{class-string, string, string}>
     * @codeCoverageIgnore
     */
    public static function insteadofResolutions(): array
    {
        // @phpstan-ignore return.type (fixture classes are not analyzed)
        return [
            'excluded trait walked first' => [
                'Fixtures\Hierarchy\TraitAdaptationReversedUser',
                'conflictMethod',
                'Fixtures\Hierarchy\ConflictingTraitA',
            ],
            'excluded trait walked second' => [
                'Fixtures\Hierarchy\TraitAdaptationUser',
                'conflictMethod',
                'Fixtures\Hierarchy\ConflictingTraitA',
            ],
        ];
    }

    /**
     * The winning trait's method is what the walk returns, even when the
     * excluded trait is used first. Without the exclusion guard the array-key
     * de-duplication lets the first-walked trait win regardless of `insteadof`.
     *
     * @param class-string $fqcn
     */
    #[DataProvider('insteadofResolutions')]
    public function testInsteadofPicksTheWinningTraitOnFind(string $fqcn, string $method, string $expectedTrait): void
    {
        $resolved = $this->resolver->findMethod(
            new ClassName($fqcn),
            new \Firehed\PhpLsp\Domain\MethodName($method),
            Visibility::Public,
        );

        self::assertNotNull($resolved, 'the conflict method should resolve');
        self::assertSame(
            $expectedTrait,
            $resolved->getDeclaringClass()->fqn,
            'insteadof must pick the winning trait, regardless of trait-use order',
        );
    }

    /**
     * @param class-string $fqcn
     */
    #[DataProvider('insteadofResolutions')]
    public function testInsteadofPicksTheWinningTraitInCollectMembers(
        string $fqcn,
        string $method,
        string $expectedTrait,
    ): void {
        $methods = $this->resolver->getMethods(new ClassName($fqcn), Visibility::Public);
        $conflicting = null;
        foreach ($methods as $candidate) {
            if ($candidate->name->name === $method) {
                $conflicting = $candidate;
                break;
            }
        }

        self::assertNotNull($conflicting, 'the conflict method should appear in getMethods');
        self::assertSame(
            $expectedTrait,
            $conflicting->getDeclaringClass()->fqn,
            'insteadof must pick the winning trait for enumerated members too',
        );
    }

    public function testFindMethodResolvesAnAliasByItsNewName(): void
    {
        $resolved = $this->resolver->findMethod(
            new ClassName('Fixtures\Hierarchy\TraitAdaptationUser'),
            new \Firehed\PhpLsp\Domain\MethodName('conflictMethodFromB'),
            Visibility::Public,
        );

        self::assertNotNull($resolved, 'an `as` alias must be reachable by findMethod');
        self::assertSame(
            'conflictMethodFromB',
            $resolved->getName()->name,
            'the returned method is exposed under the alias name',
        );
        self::assertSame(
            'Fixtures\Hierarchy\ConflictingTraitB',
            $resolved->getDeclaringClass()->fqn,
            'the alias resolves to the source trait',
        );
    }

    public function testAliasReplacesAnAlreadyWalkedInheritedMethod(): void
    {
        $methods = $this->resolver->getMethods(
            new ClassName('Fixtures\Hierarchy\TraitAliasCollidingUser'),
            Visibility::Public,
        );
        $collision = null;
        foreach ($methods as $candidate) {
            if ($candidate->name->name === 'inheritedMethod') {
                $collision = $candidate;
                break;
            }
        }

        self::assertNotNull($collision, 'the aliased method must appear exactly once');
        self::assertSame(
            'Fixtures\Hierarchy\ConflictingTraitA',
            $collision->getDeclaringClass()->fqn,
            'the trait alias must replace the parent method the walk already collected',
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
