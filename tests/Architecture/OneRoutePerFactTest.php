<?php

declare(strict_types=1);

namespace Firehed\PhpLsp\Tests\Architecture;

use Firehed\PhpLsp\Domain\DocblockParser;
use Firehed\PhpLsp\Domain\TypeFactory;
use Firehed\PhpLsp\Index\NamespaceCatalog;
use Firehed\PhpLsp\Index\SymbolIndex;
use Firehed\PhpLsp\Knowledge\KnowledgeStack;
use Firehed\PhpLsp\Knowledge\OpenDocumentBackend;
use Firehed\PhpLsp\Knowledge\SymbolBackend;
use Firehed\PhpLsp\Knowledge\SymbolLocator;
use Firehed\PhpLsp\Parser\SyntaxSource\PhpParserSyntaxSource;
use Firehed\PhpLsp\Parser\SyntaxSource\SyntaxSource;
use Firehed\PhpLsp\Resolution\ResolvedSymbolPresenter;
use Firehed\PhpLsp\Resolution\TextFallbackHelper;
use Firehed\PhpLsp\Server;
use PhpParser\Node;
use PhpParser\Node\Name;
use PhpParser\Node\Stmt\Class_;
use PhpParser\Node\Stmt\Namespace_;
use PhpParser\NodeFinder;
use PhpParser\NodeTraverser;
use PhpParser\NodeVisitor\NameResolver;
use PhpParser\NodeVisitorAbstract;
use PhpParser\Parser;
use PhpParser\ParserFactory;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * The one-route ledger (build-manifest step-32; RFC 1 §4.11, Appendix D).
 *
 * A fact with more than one complementary implementation has one interface, one
 * composite named `Composite<Interface>` that delegates to the others, one
 * namespace holding the whole family, and consumers typed on the interface that
 * never name an implementation. This test pins that shape: the implementations
 * are derived from `src/`, so a new one is watched from the day it exists, and
 * only the composition roots may name one.
 *
 * A route with no interface — a vendor parser, a helper on its way out, a store
 * with one home — is a confinement row naming the concrete classes and the holders
 * that may name them. Some such rows are permanent; one that a manifest step
 * gives an interface, or deletes, retires with that step.
 *
 * Every condition that fails today is recorded with the step that clears it. The
 * row asserts the condition still fails, then skips naming the step; a condition
 * that clears early, or a new violation, fails the test.
 */
final class OneRoutePerFactTest extends TestCase
{
    use ScansSourceFiles;

    private const string PROJECT_NAMESPACE = 'Firehed\\PhpLsp\\';

    /**
     * Implementations of each interface, by interface name, so the scan runs once.
     *
     * @var array<string, array<string, string>> interface => [class => file]
     */
    private static array $implementations = [];

    /**
     * @var array<string, array<Node>> file => annotated tree
     */
    private static array $trees = [];

    /**
     * @return iterable<string, array{Fact}>
     */
    public static function facts(): iterable
    {
        $rows = [
            Fact::family(
                name: 'namespace catalog',
                interface: NamespaceCatalog::class,
                roots: [KnowledgeStack::class],
                pending: ['src/Knowledge/OpenDocumentBackend.php' => 'step-46'],
                layoutPending: 'step-52',
            ),
            Fact::family(
                name: 'symbol locator',
                interface: SymbolLocator::class,
                roots: [KnowledgeStack::class],
                layoutPending: 'step-52',
            ),
            // The composite of this family implements SymbolSource today; step-51
            // folds the two interfaces into one.
            Fact::family(
                name: 'symbol backend',
                interface: SymbolBackend::class,
                roots: [KnowledgeStack::class],
                pending: ['src/Knowledge/DocumentSymbolSink.php' => 'step-43'],
                compositePending: 'step-51',
                layoutPending: 'step-52',
            ),
            Fact::confined(
                name: 'php-parser parser',
                ingredients: [Parser::class, ParserFactory::class],
                holders: [PhpParserSyntaxSource::class],
            ),
            Fact::family(
                name: 'syntax source',
                interface: SyntaxSource::class,
                roots: [Server::class],
            ),
            // No holder: the routes collapse by deletion. steps 40 and 41 move the
            // cursor-local regexes into the cursor text source, step-42 deletes the
            // class and this row with it.
            Fact::confined(
                name: 'text helper',
                ingredients: [TextFallbackHelper::class],
                holders: [],
                pending: [
                    'src/Resolution/CallContextDetector.php' => 'step-41',
                    'src/Resolution/EnclosingClassResolver.php' => 'step-42',
                    'src/Resolution/MemberAccessDetector.php' => 'step-40',
                    'src/Resolution/SymbolResolver.php' => 'step-42',
                ],
            ),
            Fact::confined(
                name: 'symbol index',
                ingredients: [SymbolIndex::class],
                holders: [OpenDocumentBackend::class],
                roots: [KnowledgeStack::class],
                pending: [
                    'src/Index/DocumentIndexer.php' => 'step-46',
                    'src/Index/WorkspaceNamespaceSource.php' => 'step-46',
                    'src/Knowledge/DocumentSymbolSink.php' => 'step-46',
                ],
            ),
            Fact::confined(
                name: 'docblock types',
                ingredients: [DocblockParser::class],
                holders: [TypeFactory::class, ResolvedSymbolPresenter::class],
                pending: ['src/Resolution/ExpressionResolver.php' => 'step-49'],
            ),
        ];

        foreach ($rows as $row) {
            yield $row->name => [$row];
        }
    }

    #[DataProvider('facts')]
    public function testOnlyAPermittedFileNamesARoute(Fact $fact): void
    {
        $waitingOn = [];

        if ($fact->interface !== null) {
            self::assertTrue(interface_exists($fact->interface), "fact '{$fact->name}': interface does not exist");
            $implementations = self::implementationsOf($fact->interface);
            self::assertGreaterThanOrEqual(
                2,
                count($implementations),
                "fact '{$fact->name}': an interface with one implementation is not a route; drop the row",
            );
            $routes = array_keys($implementations);

            $waitingOn = [...$waitingOn, ...$this->compositeCheck($fact, $routes)];
            $waitingOn = [...$waitingOn, ...$this->layoutCheck($fact, $routes)];
        } else {
            self::assertNotSame([], $fact->ingredients, "fact '{$fact->name}' registers no route");
            $routes = $fact->ingredients;
        }

        foreach ([...$fact->holders, ...$fact->roots] as $class) {
            self::assertTrue(
                class_exists($class),
                "fact '{$fact->name}' names {$class}, which does not exist; update the row with the code",
            );
        }

        // A route class may name itself (`new self`, its own constants). It may not
        // name a sibling: a composite that names a member has bound to it.
        $ownClassByFile = [];
        foreach (array_filter($routes, self::isProjectClass(...)) as $route) {
            $ownClassByFile[self::pathOf($route)] = $route;
        }
        $permitted = array_map(self::pathOf(...), [...$fact->holders, ...$fact->roots]);

        $violators = [];
        $details = [];
        foreach (self::sourceFiles() as $file) {
            $relative = self::relativePath($file);
            if (in_array($relative, $permitted, true)) {
                continue;
            }
            foreach (self::linesNaming($file, $routes) as [$line, $class]) {
                if (($ownClassByFile[$relative] ?? null) === $class) {
                    continue;
                }
                $violators[$relative] = true;
                $details[] = "{$relative}:{$line} names {$class}";
            }
        }

        $expected = array_keys($fact->pending);
        $actual = array_keys($violators);
        sort($expected);
        sort($actual);
        self::assertSame(
            $expected,
            $actual,
            "fact '{$fact->name}': the files naming a route differ from the row's pending list\n"
                . implode("\n", $details),
        );
        if ($expected !== []) {
            $steps = array_unique(array_values($fact->pending));
            sort($steps);
            $waitingOn[] = implode(', ', $steps) . ' clears ' . implode(', ', $expected);
        }

        if ($waitingOn !== []) {
            self::markTestSkipped("fact '{$fact->name}' waits: " . implode('; ', $waitingOn));
        }
    }

    /**
     * A scanner that reports nothing reads the same as a satisfied rule. The canary
     * names one class in every form a file can, and every form must be reported.
     */
    public function testScannerCatchesEveryReferenceForm(): void
    {
        $canary = self::root() . '/tests/Architecture/data/names-an-ingredient.php';
        $ingredient = 'Firehed\\PhpLsp\\Tests\\Architecture\\Data\\Routes\\Ingredient';

        $lines = array_map(static fn (array $hit): int => $hit[0], self::linesNaming($canary, [$ingredient]));
        sort($lines);

        self::assertSame(
            [10, 13, 16, 19, 25, 28, 34, 36, 38, 40, 44],
            $lines,
            'import, extends, property type, parameter type, return type, new, static call, '
                . 'class constant, instanceof, ::class, and catch must each be reported',
        );
    }

    public function testImplementationScanFindsTheKnownFamily(): void
    {
        $found = array_keys(self::implementationsOf(SymbolLocator::class));
        sort($found);

        self::assertSame(
            [
                'Firehed\\PhpLsp\\Index\\AutoloadFilesLocator',
                'Firehed\\PhpLsp\\Index\\ComposerSymbolLocator',
                'Firehed\\PhpLsp\\Knowledge\\CompositeSymbolLocator',
            ],
            $found,
            'the implementation scan must see a class that implements the interface among others',
        );
    }

    /**
     * The composite is named `Composite<Interface>` everywhere, so one name means one
     * thing. Returns the reason the row is waiting, if it is.
     *
     * @param list<string> $routes
     * @return list<string>
     */
    private function compositeCheck(Fact $fact, array $routes): array
    {
        assert($fact->interface !== null);
        $expected = self::namespaceOf($fact->interface) . '\\Composite' . self::shortNameOf($fact->interface);
        $present = in_array($expected, $routes, true);

        if ($fact->compositePending === null) {
            self::assertTrue($present, "fact '{$fact->name}': no implementation named {$expected}");
            return [];
        }
        self::assertFalse(
            $present,
            "fact '{$fact->name}': {$expected} exists; remove the row's compositePending entry",
        );
        return ["{$fact->compositePending} settles the composite ({$expected} is absent)"];
    }

    /**
     * The interface, its composite, and every implementation share one namespace
     * named for the interface. Returns the reason the row is waiting, if it is.
     *
     * @param list<string> $routes
     * @return list<string>
     */
    private function layoutCheck(Fact $fact, array $routes): array
    {
        assert($fact->interface !== null);
        // The family namespace is the interface's own namespace, and that namespace
        // is named for the interface: Knowledge\SymbolLocator\SymbolLocator.
        $family = self::namespaceOf($fact->interface);
        $misplaced = array_values(array_filter(
            $routes,
            static fn (string $class): bool => self::namespaceOf($class) !== $family,
        ));
        $short = self::shortNameOf($fact->interface);
        $target = $family;
        if (self::shortNameOf($family) !== $short) {
            $misplaced[] = $fact->interface;
            $target = $family . '\\' . $short;
        }

        if ($fact->layoutPending === null) {
            self::assertSame(
                [],
                $misplaced,
                "fact '{$fact->name}': these classes are outside the family namespace {$target}",
            );
            return [];
        }
        self::assertNotSame(
            [],
            $misplaced,
            "fact '{$fact->name}': the family is already in its namespace; remove the row's layoutPending entry",
        );
        return ["{$fact->layoutPending} moves the family into {$target}"];
    }

    /**
     * Every class under `src/` that implements `$interface`, as class => file.
     *
     * @param class-string $interface
     * @return array<string, string>
     */
    private static function implementationsOf(string $interface): array
    {
        if (!array_key_exists($interface, self::$implementations)) {
            $found = [];
            foreach (self::sourceFiles() as $file) {
                foreach (self::classesImplementing($file, $interface) as $class) {
                    $found[$class] = self::relativePath($file);
                }
            }
            self::$implementations[$interface] = $found;
        }
        return self::$implementations[$interface];
    }

    /**
     * Classes in `$file` that name `$interface` in their `implements` clause. Every
     * class in `src/` is final, so an implementation inherited from a parent class
     * does not arise; if one ever does, it is unwatched until this reads parents too.
     *
     * @return list<string>
     */
    private static function classesImplementing(string $file, string $interface): array
    {
        $wanted = strtolower($interface);
        $classes = [];
        foreach ((new NodeFinder())->findInstanceOf(self::tree($file), Class_::class) as $class) {
            $name = $class->namespacedName;
            if ($name === null) {
                continue;
            }
            foreach ($class->implements as $implemented) {
                if (strtolower($implemented->toString()) === $wanted) {
                    $classes[] = $name->toString();
                }
            }
        }
        return $classes;
    }

    /**
     * Every reference in `$file` to one of `$classes`, as [line, class named].
     *
     * @param list<string> $classes
     * @return list<array{int, string}>
     */
    private static function linesNaming(string $file, array $classes): array
    {
        $wanted = array_combine(array_map(strtolower(...), $classes), $classes);
        $hits = [];
        foreach ((new NodeFinder())->findInstanceOf(self::tree($file), Name::class) as $name) {
            if ($name->hasAttribute('isNamespaceDeclaration')) {
                continue;
            }
            $named = $wanted[strtolower($name->toString())] ?? null;
            if ($named !== null) {
                $hits[] = [$name->getStartLine(), $named];
            }
        }
        return $hits;
    }

    /**
     * The file's tree with every name fully qualified, so an aliased or relative
     * reference compares like a literal one, and the namespace declaration's own
     * name marked so it is not read as a class reference. Parsed once per file: every
     * row scans every file.
     *
     * @return array<Node>
     */
    private static function tree(string $file): array
    {
        if (array_key_exists($file, self::$trees)) {
            return self::$trees[$file];
        }

        $content = file_get_contents($file);
        self::assertIsString($content, "unable to read {$file}");

        $parser = (new ParserFactory())->createForNewestSupportedVersion();
        $ast = $parser->parse($content) ?? [];

        $traverser = new NodeTraverser();
        $traverser->addVisitor(new NameResolver());
        $traverser->addVisitor(new class extends NodeVisitorAbstract {
            public function enterNode(Node $node): ?int
            {
                if ($node instanceof Namespace_) {
                    $node->name?->setAttribute('isNamespaceDeclaration', true);
                }
                return null;
            }
        });

        return self::$trees[$file] = $traverser->traverse($ast);
    }

    private static function isProjectClass(string $class): bool
    {
        return str_starts_with($class, self::PROJECT_NAMESPACE);
    }

    private static function pathOf(string $class): string
    {
        return 'src/' . str_replace('\\', '/', substr($class, strlen(self::PROJECT_NAMESPACE))) . '.php';
    }

    private static function namespaceOf(string $class): string
    {
        return substr($class, 0, (int) strrpos($class, '\\'));
    }

    private static function shortNameOf(string $class): string
    {
        return substr($class, (int) strrpos($class, '\\') + 1);
    }
}
