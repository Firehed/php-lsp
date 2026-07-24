<?php

declare(strict_types=1);

namespace Firehed\PhpLsp\Tests\Parity;

use Firehed\PhpLsp\Document\TextDocument;
use Firehed\PhpLsp\Domain\ClassInfo;
use Firehed\PhpLsp\Domain\ClassName;
use Firehed\PhpLsp\Index\ComposerClassLocator;
use Firehed\PhpLsp\Parser\ParserService;
use Firehed\PhpLsp\Repository\DefaultClassInfoFactory;
use Firehed\PhpLsp\Repository\DefaultClassRepository;
use PHPUnit\Framework\Attributes\CoversClass;
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
#[CoversClass(DefaultClassRepository::class)]
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
        'Fixtures\Domain\Describable',
        'Fixtures\Domain\Entity',
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
        'Psr\Http\Message\RequestInterface',
        'Psr\Http\Message\ServerRequestInterface',
        'Fixtures\ThisClassDoesNotExist',
    ];

    private string $projectRoot;
    private DefaultClassRepository $repository;

    public static function setUpBeforeClass(): void
    {
        // The vendored PSR classes must be loadable so the repository resolves
        // them from the locked source, keeping the golden deterministic.
        require_once dirname(__DIR__) . '/Fixtures/vendor/autoload.php';
    }

    protected function setUp(): void
    {
        $this->projectRoot = dirname(__DIR__, 2);
        $this->repository = new DefaultClassRepository(
            new DefaultClassInfoFactory(),
            new ComposerClassLocator($this->projectRoot . '/tests/Fixtures'),
            new ParserService(),
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

    public function testBuiltinResolvesThroughReflectionFallback(): void
    {
        // Covers the reflection fallback for a class the locator cannot find on
        // disk. The full member set is PHP-version-specific, so only stable
        // identity is asserted — not frozen into a golden.
        $info = $this->repository->get(new ClassName(\ArrayObject::class));

        self::assertNotNull($info, 'a built-in class must resolve via the reflection fallback');
        self::assertSame('ArrayObject', $info->name->shortName(), 'reflection fallback must report the built-in');
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
            'file' => $info->file === null
                ? null
                : GoldenCodec::relativizePath($info->file, $this->projectRoot),
            'line' => $info->line,
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
