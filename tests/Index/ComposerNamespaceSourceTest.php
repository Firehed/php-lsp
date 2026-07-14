<?php

declare(strict_types=1);

namespace Firehed\PhpLsp\Tests\Index;

use Firehed\PhpLsp\Index\CatalogSymbol;
use Firehed\PhpLsp\Index\ComposerAutoloadMap;
use Firehed\PhpLsp\Index\ComposerNamespaceSource;
use Firehed\PhpLsp\Index\NamespaceContents;
use Firehed\PhpLsp\Resolution\NameKind;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * The fixtures directory is itself a Composer project exercising all three
 * autoload strategies (PSR-4, PSR-0, classmap) and installing a real vendor
 * package, so it doubles as the test bed for vendor discovery.
 */
#[CoversClass(ComposerNamespaceSource::class)]
#[CoversClass(ComposerAutoloadMap::class)]
#[CoversClass(CatalogSymbol::class)]
#[CoversClass(NamespaceContents::class)]
class ComposerNamespaceSourceTest extends TestCase
{
    private const FIXTURES_ROOT = __DIR__ . '/../Fixtures';

    private ComposerNamespaceSource $source;

    protected function setUp(): void
    {
        $this->source = new ComposerNamespaceSource(ComposerAutoloadMap::fromProjectRoot(self::FIXTURES_ROOT));
    }

    public function testPsr4PrefixesAppearAsNamespacesFromTheGlobalNamespace(): void
    {
        $contents = $this->source->childrenOf('');

        self::assertContains(
            'Fixtures',
            $contents->childNamespaces,
            'A PSR-4 prefix is a child of the global namespace',
        );
        self::assertContains(
            'Psr',
            $contents->childNamespaces,
            'A vendor package\'s prefix is discoverable without indexing vendor/',
        );
    }

    public function testIntermediateNamespacesComeFromThePrefixItself(): void
    {
        $contents = $this->source->childrenOf('Psr');

        self::assertSame(
            ['Psr\Http'],
            $contents->childNamespaces,
            'The intermediate segments of a PSR-4 prefix are known without touching the disk',
        );
        self::assertSame([], $contents->symbols, 'No files map to this namespace');
    }

    public function testVendorSymbolsAreListedFromTheDirectory(): void
    {
        $contents = $this->source->childrenOf('Psr\Http\Message');

        self::assertContains(
            'Psr\Http\Message\RequestInterface',
            self::fqns($contents),
            'A PSR-4 namespace maps to a directory, so its contents are a directory listing',
        );
        self::assertContains(
            'Psr\Http\Message\ServerRequestInterface',
            self::fqns($contents),
            'All files in the directory are symbols of that namespace',
        );
    }

    public function testSymbolsFromADirectoryAreClassLikes(): void
    {
        $contents = $this->source->childrenOf('Psr\Http\Message');

        foreach ($contents->symbols as $symbol) {
            self::assertSame(
                NameKind::ClassLike,
                $symbol->kind,
                'A file in an autoloaded directory declares a class-like; which one it is takes parsing',
            );
        }
    }

    public function testSubdirectoriesBecomeChildNamespaces(): void
    {
        $contents = $this->source->childrenOf('Fixtures');

        self::assertContains(
            'Fixtures\Domain',
            $contents->childNamespaces,
            'A subdirectory of a PSR-4 root is a child namespace',
        );
        self::assertContains(
            'Fixtures\Domain\User',
            self::fqns($this->source->childrenOf('Fixtures\Domain')),
            'Files in that subdirectory are its symbols',
        );
    }

    public function testPsr0PrefixesAreDiscovered(): void
    {
        $contents = $this->source->childrenOf('Psr0');

        self::assertContains(
            'Psr0\Psr0Fixture',
            self::fqns($contents),
            'PSR-0 nests the whole namespace under the base directory, unlike PSR-4',
        );
    }

    public function testClassmapEntriesAreDiscovered(): void
    {
        $contents = $this->source->childrenOf('Firehed\PhpLsp\Tests\Fixtures\Autoload');

        self::assertContains(
            'Firehed\PhpLsp\Tests\Fixtures\Autoload\ClassmapFixture',
            self::fqns($contents),
            'A classmapped class is discoverable even though no prefix maps its namespace',
        );
    }

    public function testClassmapEntriesInTheGlobalNamespace(): void
    {
        $contents = $this->source->childrenOf('');

        self::assertContains(
            'GlobalConfig',
            self::fqns($contents),
            'A classmapped class with no namespace belongs to the global namespace',
        );
    }

    public function testNamespaceMatchingIsCaseInsensitive(): void
    {
        $contents = $this->source->childrenOf('psr\http\message');

        self::assertContains(
            'Psr\Http\Message\RequestInterface',
            self::fqns($contents),
            'Namespaces are case-insensitive in PHP',
        );
    }

    public function testUnknownNamespaceIsEmpty(): void
    {
        $contents = $this->source->childrenOf('No\Such\Namespace');

        self::assertSame([], $contents->childNamespaces, 'An unknown namespace has no children');
        self::assertSame([], $contents->symbols, 'An unknown namespace has no symbols');
    }

    public function testARootNamespacePrefixEnumeratesFromItsDirectory(): void
    {
        $source = new ComposerNamespaceSource(new ComposerAutoloadMap(
            psr4: ['' => [self::FIXTURES_ROOT . '/src']],
        ));

        self::assertContains(
            'Domain',
            $source->childrenOf('')->childNamespaces,
            'A root-namespace ("": [dir]) mapping lists its directory as children of the global namespace',
        );
    }

    public function testAProjectWithoutComposerYieldsNothing(): void
    {
        $source = new ComposerNamespaceSource(ComposerAutoloadMap::fromProjectRoot('/nonexistent'));

        $contents = $source->childrenOf('');

        self::assertSame([], $contents->childNamespaces, 'A project with no vendor/ still works');
        self::assertSame([], $contents->symbols, 'A project with no vendor/ still works');
    }

    public function testAPrefixWhoseDirectoryIsMissingYieldsNothing(): void
    {
        $source = new ComposerNamespaceSource(new ComposerAutoloadMap(
            psr4: ['Stale\\' => ['/nonexistent/src']],
        ));

        self::assertSame(
            [],
            $source->childrenOf('Stale')->symbols,
            'An autoload map can outlive the directory it points at; that is not a crash',
        );
        self::assertSame(
            [],
            $source->childrenOf('Stale\Sub')->symbols,
            'Nor when the walk into the missing directory has segments left to match',
        );
    }

    public function testANamespaceWithNoDirectoryUnderItsPrefixYieldsNothing(): void
    {
        $contents = $this->source->childrenOf('Fixtures\NotADirectory');

        self::assertSame(
            [],
            $contents->symbols,
            'The prefix matches but nothing on disk does',
        );
        self::assertSame([], $contents->childNamespaces, 'The prefix matches but nothing on disk does');
    }

    public function testNonPhpFilesAreNotSymbols(): void
    {
        // The fixtures root holds composer.json and a vendor/ directory beside
        // its PHP, so pointing a prefix at it exercises both skips.
        $source = new ComposerNamespaceSource(new ComposerAutoloadMap(
            psr4: ['Root\\' => [self::FIXTURES_ROOT]],
        ));

        $contents = $source->childrenOf('Root');

        self::assertNotContains(
            'Root\composer',
            self::fqns($contents),
            'composer.json is not a class-like; only .php files are',
        );
        self::assertContains(
            'Root\NoNamespace',
            self::fqns($contents),
            'The .php files beside it still are',
        );
        self::assertContains(
            'Root\src',
            $contents->childNamespaces,
            'Directories are child namespaces',
        );
    }

    /**
     * @return list<string>
     */
    private static function fqns(NamespaceContents $contents): array
    {
        return array_map(
            static fn(CatalogSymbol $symbol): string => $symbol->fullyQualifiedName,
            $contents->symbols,
        );
    }
}
