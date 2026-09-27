<?php

declare(strict_types=1);

namespace Firehed\PhpLsp\Tests\Knowledge;

use Firehed\PhpLsp\Domain\ComposerAutoloadMap;
use Firehed\PhpLsp\Domain\FileUri;
use Firehed\PhpLsp\Knowledge\ComposerAutoloadMapReader;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * The reader owns the {@see ComposerAutoloadMap}: it reads from vendor/composer
 * on first use, returns a stable instance across reads, and re-reads when a
 * file under vendor/composer changes on disk. That last behavior is what lets
 * the disk backends detect a `composer install` by identity comparison against
 * the map they built their derived indexes from (RFC 1 §5.2, §5.3).
 */
#[CoversClass(ComposerAutoloadMapReader::class)]
final class ComposerAutoloadMapReaderTest extends TestCase
{
    private string $workspace;

    protected function setUp(): void
    {
        $workspace = tempnam(sys_get_temp_dir(), 'php-lsp-reader-');
        self::assertNotFalse($workspace, 'a temp workspace path must be obtainable');
        unlink($workspace);
        self::assertTrue(mkdir($workspace . '/vendor/composer', 0777, true), 'vendor/composer must be creatable');

        $this->workspace = $workspace;
    }

    protected function tearDown(): void
    {
        $entries = glob($this->workspace . '/vendor/composer/*');
        foreach ($entries === false ? [] : $entries as $file) {
            unlink($file);
        }
        @rmdir($this->workspace . '/vendor/composer');
        @rmdir($this->workspace . '/vendor');
        @rmdir($this->workspace);
    }

    public function testCurrentReadsTheMapFromDisk(): void
    {
        $this->writePsr4(['App\\' => ['/tmp/app']]);
        $reader = new ComposerAutoloadMapReader($this->workspace);

        self::assertSame(
            ['App\\' => ['/tmp/app']],
            $reader->current()->psr4Prefixes(),
            'the reader returns the map produced from the generated files',
        );
    }

    public function testCurrentReturnsTheSameInstanceUntilInvalidated(): void
    {
        $this->writePsr4(['App\\' => ['/tmp/app']]);
        $reader = new ComposerAutoloadMapReader($this->workspace);

        self::assertSame(
            $reader->current(),
            $reader->current(),
            'repeated calls must reuse the same map instance so backends can detect changes by identity',
        );
    }

    public function testInvalidateUnderVendorComposerRecomputesTheMap(): void
    {
        $this->writePsr4(['App\\' => ['/tmp/app']]);
        $reader = new ComposerAutoloadMapReader($this->workspace);

        $first = $reader->current();
        $this->writePsr4(['App\\' => ['/tmp/app'], 'Lib\\' => ['/tmp/lib']]);
        $reader->invalidate(FileUri::fromPath($this->workspace . '/vendor/composer/autoload_psr4.php'));
        $second = $reader->current();

        self::assertNotSame($first, $second, 'a vendor/composer change must drop the cached map');
        self::assertSame(
            ['App\\' => ['/tmp/app'], 'Lib\\' => ['/tmp/lib']],
            $second->psr4Prefixes(),
            'the re-read must reflect the regenerated autoload file',
        );
    }

    public function testInvalidateOutsideVendorComposerLeavesTheMapAlone(): void
    {
        $this->writePsr4(['App\\' => ['/tmp/app']]);
        $reader = new ComposerAutoloadMapReader($this->workspace);
        $first = $reader->current();

        $reader->invalidate(FileUri::fromPath($this->workspace . '/src/Widget.php'));

        self::assertSame(
            $first,
            $reader->current(),
            'a change to a source file outside vendor/composer must not invalidate the map',
        );
    }

    public function testFromMapReturnsAReaderThatServesTheGivenMap(): void
    {
        $map = new ComposerAutoloadMap(psr4: ['Given\\' => ['/tmp/given']]);
        $reader = ComposerAutoloadMapReader::fromMap($map);

        self::assertSame(
            $map,
            $reader->current(),
            'fromMap returns a reader that serves the given map without reading disk',
        );
    }

    /**
     * @param array<string, list<string>> $prefixes
     */
    private function writePsr4(array $prefixes): void
    {
        $path = $this->workspace . '/vendor/composer/autoload_psr4.php';
        self::assertNotFalse(
            file_put_contents($path, "<?php\nreturn " . var_export($prefixes, true) . ";\n"),
            'the generated PSR-4 map must be writable',
        );
    }
}
