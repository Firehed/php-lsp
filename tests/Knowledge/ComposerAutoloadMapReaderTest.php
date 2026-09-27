<?php

declare(strict_types=1);

namespace Firehed\PhpLsp\Tests\Knowledge;

use Firehed\PhpLsp\Domain\ComposerAutoloadMap;
use Firehed\PhpLsp\Domain\FileUri;
use Firehed\PhpLsp\Events\EventDispatcher;
use Firehed\PhpLsp\Events\ListenerProvider;
use Firehed\PhpLsp\Events\WatchedFileChangedEvent;
use Firehed\PhpLsp\Knowledge\AutoloadMapRegeneratedEvent;
use Firehed\PhpLsp\Knowledge\ComposerAutoloadMapReader;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Psr\EventDispatcher\EventDispatcherInterface;

/**
 * The reader owns the {@see ComposerAutoloadMap}: it reads from vendor/composer
 * on first use, returns a stable instance across reads, and re-reads when a
 * file under vendor/composer changes on disk — publishing an
 * {@see AutoloadMapRegeneratedEvent} that carries the fresh map to downstream
 * subscribers (RFC 1 §5.2, §5.3).
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
        @rmdir($this->workspace);
    }

    public function testCurrentReadsTheMapFromDisk(): void
    {
        $this->writePsr4(['App\\' => ['/tmp/app']]);
        $reader = new ComposerAutoloadMapReader($this->workspace, self::nullDispatcher());

        self::assertSame(
            ['App\\' => ['/tmp/app']],
            $reader->current()->psr4Prefixes(),
            'the reader returns the map produced from the generated files',
        );
    }

    public function testCurrentReturnsTheSameInstanceUntilRegenerated(): void
    {
        $this->writePsr4(['App\\' => ['/tmp/app']]);
        $reader = new ComposerAutoloadMapReader($this->workspace, self::nullDispatcher());

        self::assertSame(
            $reader->current(),
            $reader->current(),
            'repeated calls must reuse the same map instance so subscribers can compare by identity',
        );
    }

    public function testAWatchedFileEventUnderVendorComposerRecomputesTheMapAndPublishes(): void
    {
        $this->writePsr4(['App\\' => ['/tmp/app']]);
        $received = null;
        $listeners = new ListenerProvider();
        $listeners->addListener(
            AutoloadMapRegeneratedEvent::class,
            function (AutoloadMapRegeneratedEvent $event) use (&$received): void {
                $received = $event->map;
            },
        );
        $reader = new ComposerAutoloadMapReader($this->workspace, new EventDispatcher($listeners));

        $first = $reader->current();
        $this->writePsr4(['App\\' => ['/tmp/app'], 'Lib\\' => ['/tmp/lib']]);
        $reader->onWatchedFileChanged(new WatchedFileChangedEvent(
            FileUri::fromPath($this->workspace . '/vendor/composer/autoload_psr4.php'),
        ));
        $second = $reader->current();

        self::assertNotSame($first, $second, 'a vendor/composer change must drop the cached map');
        self::assertSame(
            ['App\\' => ['/tmp/app'], 'Lib\\' => ['/tmp/lib']],
            $second->psr4Prefixes(),
            'the re-read must reflect the regenerated autoload file',
        );
        self::assertSame(
            $second,
            $received,
            'the published event must carry the fresh map so subscribers do not poll the reader',
        );
    }

    public function testAWatchedFileEventOutsideVendorComposerLeavesTheMapAlone(): void
    {
        $this->writePsr4(['App\\' => ['/tmp/app']]);
        $dispatcher = $this->createMock(EventDispatcherInterface::class);
        $dispatcher->expects($this->never())->method('dispatch');
        $reader = new ComposerAutoloadMapReader($this->workspace, $dispatcher);
        $first = $reader->current();

        $reader->onWatchedFileChanged(new WatchedFileChangedEvent(
            FileUri::fromPath($this->workspace . '/src/Widget.php'),
        ));

        self::assertSame(
            $first,
            $reader->current(),
            'a change to a source file outside vendor/composer must not regenerate the map',
        );
    }

    public function testFromMapReturnsAReaderThatServesTheGivenMap(): void
    {
        $map = new ComposerAutoloadMap(psr4: ['Given\\' => ['/tmp/given']]);
        $reader = ComposerAutoloadMapReader::fromMap($map, self::nullDispatcher());

        self::assertSame(
            $map,
            $reader->current(),
            'fromMap returns a reader that serves the given map without reading disk',
        );
    }

    private static function nullDispatcher(): EventDispatcherInterface
    {
        return new EventDispatcher(new ListenerProvider());
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
