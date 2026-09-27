<?php

declare(strict_types=1);

namespace Firehed\PhpLsp\Tests\Integration;

use Firehed\PhpLsp\Domain\CatalogSymbol;
use Firehed\PhpLsp\Domain\ClasslikeName;
use Firehed\PhpLsp\Domain\NamespaceName;
use Firehed\PhpLsp\Handler\DidChangeWatchedFilesHandler;
use Firehed\PhpLsp\Knowledge\SymbolSourceInterface;
use Firehed\PhpLsp\Protocol\NotificationMessage;
use Firehed\PhpLsp\Tests\BuildsKnowledgeStackTrait;
use Firehed\PhpLsp\Tests\Parser\ProductionSyntaxSource;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\TestCase;

/**
 * A `composer install` regenerates the autoload files under `vendor/composer/`.
 * The client reports these as watched-file changes: the map reader re-reads,
 * and both disk backends detect the new map and rebuild their derived indexes
 * from it (RFC 1 §5.2, §5.3). Regenerating the maps in place — same on-disk
 * project, new set of `psr4` prefixes — must reach lookup, enumeration and
 * search on the next query rather than serve the pre-install cache.
 */
#[CoversNothing]
final class ComposerRegenerationInvalidationTest extends TestCase
{
    use BuildsKnowledgeStackTrait;

    private string $projectRoot;

    protected function setUp(): void
    {
        $projectRoot = tempnam(sys_get_temp_dir(), 'php-lsp-regen-');
        self::assertNotFalse($projectRoot, 'a temp project path must be obtainable');
        unlink($projectRoot);
        self::assertTrue(mkdir($projectRoot . '/src', 0777, true), 'src directory must be creatable');
        self::assertTrue(mkdir($projectRoot . '/vendor/composer', 0777, true), 'vendor/composer must be creatable');

        $this->projectRoot = $projectRoot;
    }

    protected function tearDown(): void
    {
        foreach (['/vendor/composer', '/src'] as $dir) {
            $entries = glob($this->projectRoot . $dir . '/*');
            foreach ($entries === false ? [] : $entries as $file) {
                if (is_file($file)) {
                    unlink($file);
                }
            }
        }
        @rmdir($this->projectRoot . '/vendor/composer');
        @rmdir($this->projectRoot . '/vendor');
        @rmdir($this->projectRoot . '/src');
        @rmdir($this->projectRoot);
    }

    public function testRegeneratedPsr4MapReachesLookupOnTheNextQuery(): void
    {
        $this->writePsr4(['App\\' => [$this->projectRoot . '/src']]);
        file_put_contents($this->projectRoot . '/src/Widget.php', "<?php\nnamespace App;\nclass Widget {}\n");
        $stack = $this->knowledgeStackForProjectRoot($this->projectRoot, ProductionSyntaxSource::create());
        $handler = new DidChangeWatchedFilesHandler($stack->invalidator);

        self::assertNotNull(
            $stack->source->lookupClassLike(ClasslikeName::fromFullyQualified('App\\Widget')),
            'sanity: the class resolves through the pre-regeneration map',
        );

        // A composer install renames the prefix; the file still exists on disk.
        $this->writePsr4(['Renamed\\' => [$this->projectRoot . '/src']]);
        file_put_contents(
            $this->projectRoot . '/src/Widget.php',
            "<?php\nnamespace Renamed;\nclass Widget {}\n",
        );
        $handler->handle($this->changed('/vendor/composer/autoload_psr4.php'));

        self::assertNull(
            $stack->source->lookupClassLike(ClasslikeName::fromFullyQualified('App\\Widget')),
            'the pre-install namespace must no longer resolve after the map was regenerated',
        );
        self::assertNotNull(
            $stack->source->lookupClassLike(ClasslikeName::fromFullyQualified('Renamed\\Widget')),
            'the regenerated namespace must resolve on the next query',
        );
    }

    public function testRegeneratedPsr4MapReachesEnumerationOnTheNextQuery(): void
    {
        $this->writePsr4(['Before\\' => [$this->projectRoot . '/src']]);
        file_put_contents($this->projectRoot . '/src/Widget.php', "<?php\nnamespace Before;\nclass Widget {}\n");
        $stack = $this->knowledgeStackForProjectRoot($this->projectRoot, ProductionSyntaxSource::create());
        $handler = new DidChangeWatchedFilesHandler($stack->invalidator);

        // Warm the derived index by enumerating before the regeneration.
        self::assertSame(
            ['Before\\Widget'],
            $this->enumerate($stack->source, new NamespaceName('Before')),
            'sanity: the Before namespace enumerates its class before regeneration',
        );

        $this->writePsr4(['After\\' => [$this->projectRoot . '/src']]);
        file_put_contents(
            $this->projectRoot . '/src/Widget.php',
            "<?php\nnamespace After;\nclass Widget {}\n",
        );
        $handler->handle($this->changed('/vendor/composer/autoload_psr4.php'));

        self::assertSame(
            [],
            $this->enumerate($stack->source, new NamespaceName('Before')),
            'the pre-install namespace must not enumerate after regeneration',
        );
        self::assertSame(
            ['After\\Widget'],
            $this->enumerate($stack->source, new NamespaceName('After')),
            'the regenerated namespace must enumerate its class on the next query',
        );
    }

    public function testRegeneratedAutoloadFilesReachesTheDerivedIndex(): void
    {
        $entry = $this->projectRoot . '/src/bootstrap.php';
        file_put_contents($entry, "<?php\nnamespace Boot;\nclass Before {}\n");
        $this->writeAutoloadFiles([$entry]);
        $stack = $this->knowledgeStackForProjectRoot($this->projectRoot, ProductionSyntaxSource::create());
        $handler = new DidChangeWatchedFilesHandler($stack->invalidator);

        self::assertNotNull(
            $stack->source->lookupClassLike(ClasslikeName::fromFullyQualified('Boot\\Before')),
            'sanity: the pre-regeneration files entry resolves',
        );

        // A regeneration drops the entry from the files set; the file itself is unchanged.
        $this->writeAutoloadFiles([]);
        $handler->handle($this->changed('/vendor/composer/autoload_files.php'));

        self::assertNull(
            $stack->source->lookupClassLike(ClasslikeName::fromFullyQualified('Boot\\Before')),
            'a name whose entry the regeneration dropped must stop resolving',
        );
    }

    /**
     * @return list<string>
     */
    private function enumerate(SymbolSourceInterface $source, NamespaceName $namespace): array
    {
        return array_map(
            static fn(CatalogSymbol $symbol): string => $symbol->fullyQualifiedName,
            $source->childrenOf($namespace)->symbols,
        );
    }

    /**
     * @param array<string, list<string>> $prefixes
     */
    private function writePsr4(array $prefixes): void
    {
        $path = $this->projectRoot . '/vendor/composer/autoload_psr4.php';
        self::assertNotFalse(
            file_put_contents($path, "<?php\nreturn " . var_export($prefixes, true) . ";\n"),
            'the generated PSR-4 map must be writable',
        );
    }

    /**
     * @param list<string> $files
     */
    private function writeAutoloadFiles(array $files): void
    {
        $path = $this->projectRoot . '/vendor/composer/autoload_files.php';
        $entries = [];
        foreach ($files as $file) {
            $entries[hash('sha1', $file)] = $file;
        }
        self::assertNotFalse(
            file_put_contents($path, "<?php\nreturn " . var_export($entries, true) . ";\n"),
            'the generated files list must be writable',
        );
    }

    private function changed(string $relative): NotificationMessage
    {
        return NotificationMessage::fromArray([
            'jsonrpc' => '2.0',
            'method' => 'workspace/didChangeWatchedFiles',
            'params' => [
                'changes' => [
                    ['uri' => 'file://' . $this->projectRoot . $relative, 'type' => 2],
                ],
            ],
        ]);
    }
}
