<?php

declare(strict_types=1);

namespace Firehed\PhpLsp\Tests\Parity;

use Firehed\PhpLsp\Document\TextDocument;
use Firehed\PhpLsp\Domain\ClassInfo;
use Firehed\PhpLsp\Domain\ClassName;
use Firehed\PhpLsp\Index\ComposerClassLocator;
use Firehed\PhpLsp\Parser\ParserService;
use Firehed\PhpLsp\Repository\DefaultClassInfoFactory;
use Firehed\PhpLsp\Cache\CacheFactory;
use Firehed\PhpLsp\Repository\DefaultClassRepository;
use PHPUnit\Framework\TestCase;

/**
 * Golden parity for the class-like lookup surface — `ClassRepository::get()`,
 * which Step 2 migrates onto `SymbolSource::lookupClassLike`. The golden freezes
 * the observable `ClassInfo` for a curated corpus of in-repo fixture classes and
 * locked vendored classes (both deterministic across the PHP matrix). A built-in
 * resolved through the reflection fallback is version-fragile, so it is covered
 * by a stable-subset assertion rather than frozen into the golden.
 *
 * See docs/architecture/0002-execution-plan.md, Step P; RFC 1 §4.2, §5.1.
 */
final class ClassLikeLookupParityTest extends TestCase
{
    use AssertsGolden;

    /**
     * Corpus of class-like names whose full `ClassInfo` is deterministic and
     * therefore frozen. A deliberately-absent name records the not-found
     * contract (`get()` returns null).
     *
     * @var list<string>
     */
    private const array CORPUS = [
        'Fixtures\Attributes\NoConstructorAttribute',
        'Fixtures\Domain\Describable',
        'Fixtures\Domain\Entity',
        'Fixtures\Domain\Money',
        'Fixtures\Domain\Person',
        'Fixtures\Domain\Team',
        'Fixtures\Domain\User',
        'Fixtures\Enum\Color',
        'Fixtures\Enum\Priority',
        'Fixtures\Enum\SerializableStatus',
        'Fixtures\Enum\Status',
        'Fixtures\Exception\AppException',
        'Fixtures\Exception\ExceptionInterface',
        'Fixtures\Hierarchy\AbstractImplementor',
        'Fixtures\Hierarchy\BaseInterface',
        'Fixtures\Hierarchy\ConcreteDescendant',
        'Fixtures\Hierarchy\GrandchildDescendant',
        'Fixtures\Hierarchy\InnerTrait',
        'Fixtures\Hierarchy\LeafInterface',
        'Fixtures\Hierarchy\MiddleInterface',
        'Fixtures\Hierarchy\OuterTrait',
        'Fixtures\Inheritance\ChildClass',
        'Fixtures\Inheritance\FinalDescendant',
        'Fixtures\Inheritance\Grandparent',
        'Fixtures\Inheritance\ParentClass',
        'Fixtures\Repository\Repository',
        'Fixtures\Repository\UserRepository',
        'Fixtures\Traits\ConcreteService',
        'Fixtures\Traits\HasTimestamps',
        'Fixtures\Traits\SingletonTrait',
        'Fixtures\TypeInference\AnonymousClass',
        'Psr\Http\Message\RequestInterface',
        'Psr\Http\Message\ServerRequestInterface',
        'Fixtures\ThisClassDoesNotExist',
    ];

    private string $projectRoot;
    private DefaultClassRepository $repository;

    protected function setUp(): void
    {
        $this->projectRoot = dirname(__DIR__, 2);
        $this->repository = new DefaultClassRepository(
            new DefaultClassInfoFactory(),
            new ComposerClassLocator($this->projectRoot . '/tests/Fixtures'),
            new ParserService(),
            CacheFactory::inMemory(),
        );
    }

    public function testClassLikeLookupMatchesGolden(): void
    {
        $captured = [];
        foreach (self::CORPUS as $fqn) {
            $info = $this->repository->get(self::className($fqn));
            $captured[$fqn] = $info === null ? null : $this->serialize($info);
        }

        $this->assertGoldenMatches('class-like-lookup', $captured);
    }

    public function testOpenDocumentClassIsResolvedThroughGet(): void
    {
        // Covers the open-document precedence branch of get(): a registered
        // document class is returned without touching the filesystem locator.
        $uri = 'file:///virtual/Widget.php';
        $content = "<?php\nnamespace Virtual;\nfinal class Widget { public function tick(): void {} }\n";
        $parser = new ParserService();
        $document = new TextDocument($uri, 'php', 1, $content);
        $ast = $parser->parse($document);
        self::assertNotNull($ast, 'the virtual document should parse');

        $factory = new DefaultClassInfoFactory();
        $info = $factory->fromAstNode(self::firstClassLike($ast), $uri);
        $this->repository->updateDocument($uri, [$info]);

        $resolved = $this->repository->get(self::className('Virtual\Widget'));
        self::assertNotNull($resolved, 'an open-document class must resolve through get()');
        self::assertSame('Virtual\Widget', $resolved->name->fqn, 'open-document lookup must win over disk');
    }

    public function testUnresolvableLookupsReturnNull(): void
    {
        // A file the locator finds but that declares no class of that name
        // (a multi-class file), and a file that does not parse, both resolve to
        // null — the not-found contract for the located-but-unusable cases.
        self::assertNull(
            $this->repository->get(self::className('Fixtures\Utility\ClassModifiers')),
            'a located file that declares no matching class must resolve to null',
        );
        self::assertNull(
            $this->repository->get(self::className('Fixtures\IncompleteCode\VeryBroken')),
            'a located file that does not parse must resolve to null',
        );
    }

    public function testRemoveDocumentDropsRegisteredClass(): void
    {
        $uri = 'file:///virtual/Ephemeral.php';
        $content = "<?php\nnamespace Virtual;\nclass Ephemeral {}\n";
        $parser = new ParserService();
        $ast = $parser->parse(new TextDocument($uri, 'php', 1, $content));
        self::assertNotNull($ast, 'the virtual document should parse');

        $info = (new DefaultClassInfoFactory())->fromAstNode(self::firstClassLike($ast), $uri);
        $this->repository->updateDocument($uri, [$info]);
        self::assertNotNull(
            $this->repository->get(self::className('Virtual\Ephemeral')),
            'a registered class resolves while its document is open',
        );

        $this->repository->removeDocument($uri);
        self::assertNull(
            $this->repository->get(self::className('Virtual\Ephemeral')),
            'closing the document must drop its registered class',
        );
    }

    public function testIsSubclassOfTraversesTheGraph(): void
    {
        $cases = [
            ['Fixtures\Inheritance\FinalDescendant', 'Fixtures\Inheritance\ParentClass', true, 'direct parent'],
            ['Fixtures\Inheritance\FinalDescendant', 'Fixtures\Inheritance\Grandparent', true, 'grandparent via chain'],
            ['Fixtures\Inheritance\ChildClass', 'Fixtures\Inheritance\Grandparent', true, 'via cached chain'],
            ['Fixtures\Domain\User', 'Fixtures\Domain\Entity', true, 'directly implemented interface'],
            ['Fixtures\Hierarchy\ConcreteDescendant', 'Fixtures\Hierarchy\MiddleInterface', true, 'iface via parent'],
            ['Fixtures\Hierarchy\ConcreteDescendant', 'Fixtures\Hierarchy\BaseInterface', true, 'iface via iface'],
            ['Fixtures\Domain\User', 'Fixtures\Inheritance\Grandparent', false, 'unrelated class'],
            ['Fixtures\Inheritance\Grandparent', 'Fixtures\Domain\Entity', false, 'no parent, no interfaces'],
            ['Fixtures\Absent\Missing', 'Fixtures\Domain\Entity', false, 'an unresolvable subject is not a subclass'],
        ];

        foreach ($cases as [$class, $parent, $expected, $why]) {
            self::assertSame(
                $expected,
                $this->repository->isSubclassOf(self::className($class), self::className($parent)),
                "isSubclassOf should follow the type graph: {$why}",
            );
        }
    }

    public function testBuiltinResolvesThroughReflectionFallback(): void
    {
        // Covers the reflection fallback for a class the locator cannot find on
        // disk. The full member set is PHP-version-specific, so it is not frozen
        // into a golden; instead a subset of ArrayObject's long-standing methods
        // is asserted, so a regression that stops extracting reflected members —
        // whose lines still execute, but whose output the golden never sees —
        // goes red rather than passing silently.
        $info = $this->repository->get(new ClassName(\ArrayObject::class));

        self::assertNotNull($info, 'a built-in class must resolve via the reflection fallback');
        self::assertSame('ArrayObject', $info->name->shortName(), 'reflection fallback must report the built-in');

        $methodNames = array_keys($info->methods);
        foreach (['append', 'count', 'getArrayCopy', 'getIterator', 'offsetGet', 'offsetSet'] as $method) {
            self::assertContains(
                $method,
                $methodNames,
                "the reflection fallback must extract ArrayObject::{$method}",
            );
        }

        // Name presence alone would pass even if the reflection path mis-extracted a
        // member's visibility or parameters — those lines execute, but their output
        // is not frozen into the golden (built-in signatures are version-fragile).
        // Pin the formatted shape of one member to close that gap. The prefix below
        // is stable across the 8.3/8.4/8.5 matrix — it deliberately stops before the
        // parameter *names* (which internal reflection can vary) — yet still pins the
        // visibility, the method name, and the first typed parameter, so a reflection
        // path that dropped the visibility or the parameter list goes red rather than
        // passing behind the name check. (Return types are omitted: ArrayObject's are
        // tentative, so reflection reports none.)
        self::assertStringStartsWith(
            'public function offsetSet(mixed $',
            $info->methods['offsetSet']->format(),
            'the reflection fallback must format visibility and typed parameters, not just the name',
        );

        // Method presence alone would also pass if the reflection path stopped
        // extracting interfaces entirely: those lines execute, but their output is
        // not frozen into the golden. ArrayObject's interface set is long-standing
        // and stable across the 8.3/8.4/8.5 matrix, so pin a subset — a regression
        // that returned no interfaces goes red rather than surviving behind the
        // method check.
        $interfaceFqns = array_map(
            static fn(ClassName $name): string => $name->fqn,
            $info->interfaces,
        );
        foreach (['ArrayAccess', 'Countable', 'IteratorAggregate'] as $interface) {
            self::assertContains(
                $interface,
                $interfaceFqns,
                "the reflection fallback must extract ArrayObject's {$interface} interface",
            );
        }
    }

    /**
     * Fixture, vendored, and synthetic names are intentionally outside PHPStan's
     * autoload path, so they are not seen as class-strings. The repository reads
     * only the FQN, so the concession is harmless and confined here.
     */
    private static function className(string $fqn): ClassName
    {
        /** @phpstan-ignore argument.type (corpus names are not analyzed) */
        return new ClassName($fqn);
    }

    /**
     * @param array<\PhpParser\Node\Stmt> $ast
     */
    private static function firstClassLike(array $ast): \PhpParser\Node\Stmt\ClassLike
    {
        $node = (new \PhpParser\NodeFinder())->findFirstInstanceOf($ast, \PhpParser\Node\Stmt\ClassLike::class);
        self::assertInstanceOf(\PhpParser\Node\Stmt\ClassLike::class, $node, 'the fixture must declare a class-like');

        return $node;
    }

    /**
     * @return array<string, mixed>
     */
    private function serialize(ClassInfo $info): array
    {
        return [
            'kind' => $info->kind->name,
            'signature' => $info->format(),
            'isAbstract' => $info->isAbstract,
            'isFinal' => $info->isFinal,
            'isReadonly' => $info->isReadonly,
            'isAttribute' => $info->isAttribute,
            'parent' => $info->parent?->fqn,
            'interfaces' => self::sortedFqns($info->interfaces),
            'traits' => self::sortedFqns($info->traits),
            'methods' => self::formatted($info->methods),
            'properties' => self::formatted($info->properties),
            'constants' => self::formatted($info->constants),
            'enumCases' => self::formatted($info->enumCases),
            'docblock' => $info->docblock,
            // The resolved file is captured (it proves *which* source answered),
            // but not the line: a line number shifts on any edit above the symbol,
            // which is churn unrelated to what this surface returns.
            'file' => $info->file === null
                ? null
                : GoldenCodec::relativizePath($info->file, $this->projectRoot),
        ];
    }

    /**
     * @param list<ClassName> $names
     * @return list<string>
     */
    private static function sortedFqns(array $names): array
    {
        $fqns = array_map(static fn(ClassName $name): string => $name->fqn, $names);
        sort($fqns);

        return $fqns;
    }

    /**
     * @param array<string, \Firehed\PhpLsp\Domain\Formattable> $members
     * @return array<string, string>
     */
    private static function formatted(array $members): array
    {
        $formatted = [];
        foreach ($members as $name => $member) {
            $formatted[$name] = $member->format();
        }
        ksort($formatted);

        return $formatted;
    }
}
