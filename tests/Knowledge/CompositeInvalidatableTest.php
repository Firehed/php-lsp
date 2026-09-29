<?php

declare(strict_types=1);

namespace Firehed\PhpLsp\Tests\Knowledge;

use Firehed\PhpLsp\Domain\ComposerAutoloadMap;
use Firehed\PhpLsp\Domain\FileUri;
use Firehed\PhpLsp\Domain\FunctionName;
use Firehed\PhpLsp\Domain\NameKind;
use Firehed\PhpLsp\Domain\Symbol;
use Firehed\PhpLsp\Filesystem\PhpDirectoryReader;
use Firehed\PhpLsp\Knowledge\AutoloadFilesBackend;
use Firehed\PhpLsp\Knowledge\ComposerAutoloadMapReader;
use Firehed\PhpLsp\Knowledge\ComposerMapBackend;
use Firehed\PhpLsp\Knowledge\CompositeInvalidatable;
use Firehed\PhpLsp\Tests\LoadsFixturesTrait;
use Firehed\PhpLsp\Tests\Parser\ProductionSyntaxSource;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * One invalidation must reach every member: each test changes the disk under
 * one member and asks that member, having told only the composite.
 */
#[CoversClass(CompositeInvalidatable::class)]
final class CompositeInvalidatableTest extends TestCase
{
    use LoadsFixturesTrait;

    private string $workspace;

    protected function setUp(): void
    {
        $workspace = tempnam(sys_get_temp_dir(), 'php-lsp-fanout-');
        self::assertNotFalse($workspace, 'a temp workspace path must be obtainable');
        unlink($workspace);
        self::assertTrue(mkdir($workspace . '/src/Domain', 0777, true), 'the source tree must be creatable');
        self::assertTrue(mkdir($workspace . '/vendor/composer', 0777, true), 'vendor/composer must be creatable');

        $this->workspace = $workspace;
    }

    protected function tearDown(): void
    {
        foreach (
            [
                '/src/Domain/User.php',
                '/src/Domain/Entity.php',
                '/bootstrap.php',
                '/vendor/composer/autoload_psr4.php',
            ] as $file
        ) {
            @unlink($this->workspace . $file);
        }
        foreach (['/src/Domain', '/src', '/vendor/composer', '/vendor', ''] as $directory) {
            rmdir($this->workspace . $directory);
        }
    }

    public function testInvalidateReachesTheMapReader(): void
    {
        $this->writePsr4(['Fixtures\\' => [$this->workspace . '/src']]);
        $mapReader = new ComposerAutoloadMapReader($this->workspace);
        $before = $mapReader->current();

        $this->composite($mapReader)->invalidate(
            FileUri::fromPath($this->workspace . '/vendor/composer/autoload_psr4.php'),
        );

        self::assertNotSame($before, $mapReader->current(), 'a regenerated map must be read again');
    }

    public function testInvalidateReachesTheComposerMapBackend(): void
    {
        $this->place('src/Domain/User.php', 'src/Domain/User.php');
        $mapReader = ComposerAutoloadMapReader::fromMap(new ComposerAutoloadMap(
            psr4: ['Fixtures\\' => [$this->workspace . '/src']],
        ));
        $maps = $this->composerMapBackend($mapReader);
        self::assertSame(
            ['Fixtures\Domain\User'],
            self::names($maps->search('', NameKind::ClassLike)),
            'the name list must be built for it to be able to go stale',
        );

        $this->place('src/Domain/Entity.php', 'src/Domain/Entity.php');
        $this->composite($mapReader, maps: $maps)->invalidate(
            FileUri::fromPath($this->workspace . '/src/Domain/Entity.php'),
        );

        self::assertSame(
            ['Fixtures\Domain\User', 'Fixtures\Domain\Entity'],
            self::names($maps->search('', NameKind::ClassLike)),
            'a file created under an autoload root must join the name list',
        );
    }

    public function testInvalidateReachesTheAutoloadFilesBackend(): void
    {
        $entry = $this->workspace . '/bootstrap.php';
        $this->place('src/Catalog/functions.php', 'bootstrap.php');
        $mapReader = ComposerAutoloadMapReader::fromMap(new ComposerAutoloadMap(files: [$entry]));
        $files = $this->autoloadFilesBackend($mapReader);
        $added = FunctionName::fromFullyQualified('Fixtures\Helpers\helperFormat');
        self::assertNull($files->lookupFunction($added), 'the entry does not declare the function yet');

        $this->place('AutoloadFiles/helpers.php', 'bootstrap.php');
        $this->composite($mapReader, files: $files)->invalidate(FileUri::fromPath($entry));

        self::assertNotNull($files->lookupFunction($added), 'a changed entry must be read again');
    }

    private function composite(
        ComposerAutoloadMapReader $mapReader,
        ?ComposerMapBackend $maps = null,
        ?AutoloadFilesBackend $files = null,
    ): CompositeInvalidatable {
        return new CompositeInvalidatable(
            $mapReader,
            $maps ?? $this->composerMapBackend($mapReader),
            $files ?? $this->autoloadFilesBackend($mapReader),
        );
    }

    private function composerMapBackend(ComposerAutoloadMapReader $mapReader): ComposerMapBackend
    {
        $production = ProductionSyntaxSource::create();

        return new ComposerMapBackend(
            $mapReader,
            $production->reader,
            $production->declarations,
            new PhpDirectoryReader(),
        );
    }

    private function autoloadFilesBackend(ComposerAutoloadMapReader $mapReader): AutoloadFilesBackend
    {
        $production = ProductionSyntaxSource::create();

        return new AutoloadFilesBackend($mapReader, $production->reader, $production->declarations);
    }

    private function place(string $fixture, string $relative): void
    {
        self::assertTrue(
            copy($this->fixturePath($fixture), $this->workspace . '/' . $relative),
            "{$relative} must be writable",
        );
    }

    /**
     * @param array<string, list<string>> $prefixes
     */
    private function writePsr4(array $prefixes): void
    {
        self::assertNotFalse(
            file_put_contents(
                $this->workspace . '/vendor/composer/autoload_psr4.php',
                "<?php\nreturn " . var_export($prefixes, true) . ";\n",
            ),
            'the generated PSR-4 map must be writable',
        );
    }

    /**
     * @param list<Symbol> $symbols
     * @return list<string>
     */
    private static function names(array $symbols): array
    {
        return array_map(static fn(Symbol $symbol): string => $symbol->fullyQualifiedName, $symbols);
    }
}
