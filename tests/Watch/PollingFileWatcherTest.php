<?php

declare(strict_types=1);

namespace Firehed\PhpLsp\Tests\Watch;

use Firehed\PhpLsp\Cache\InvalidatableInterface;
use Firehed\PhpLsp\Capability\SessionCapabilities;
use Firehed\PhpLsp\Domain\FileUri;
use Firehed\PhpLsp\Filesystem\PhpDirectoryReader;
use Firehed\PhpLsp\Filesystem\StatReader;
use Firehed\PhpLsp\Tests\LoadsFixturesTrait;
use Firehed\PhpLsp\Watch\PollingFileWatcher;
use Firehed\PhpLsp\Watch\WatchedPaths;
use Firehed\PhpLsp\Watch\WatchedPathsSourceInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * Every modification time is set explicitly and the clock is the test's own, so
 * nothing here depends on how fast the test runs.
 */
#[CoversClass(PollingFileWatcher::class)]
final class PollingFileWatcherTest extends TestCase
{
    use LoadsFixturesTrait;

    private const int LONG_AGO = 1_000_000;

    private string $root;

    private int $now = self::LONG_AGO + 1000;

    /** @var list<string> */
    private array $invalidated = [];

    private WatchedPaths $watched;

    private PollingFileWatcher $watcher;

    protected function setUp(): void
    {
        $root = tempnam(sys_get_temp_dir(), 'php-lsp-watch-');
        self::assertNotFalse($root, 'a temp path must be obtainable');
        unlink($root);
        self::assertTrue(mkdir($root . '/src/Nested', 0777, true), 'the watched tree must be creatable');
        // macOS hands out temp paths through a symlink; the reader reports real ones.
        $this->root = (string) realpath($root);

        $this->place('src/Widget.php');
        $this->place('src/Nested/Gadget.php');
        $this->place('bootstrap.php');
        $this->settle();

        $this->watched = new WatchedPaths(
            roots: [$this->root . '/src'],
            files: [$this->root . '/bootstrap.php'],
        );

        $source = self::createStub(WatchedPathsSourceInterface::class);
        $source->method('watchedPaths')->willReturnCallback(fn(): WatchedPaths => $this->watched);

        $invalidator = self::createStub(InvalidatableInterface::class);
        $invalidator->method('invalidate')->willReturnCallback(function (string $uri): void {
            $this->invalidated[] = $uri;
        });

        $this->watcher = new PollingFileWatcher(
            $source,
            $invalidator,
            new PhpDirectoryReader(),
            new StatReader(),
            fn(): int => $this->now,
        );
        $this->watcher->onInitialized(new SessionCapabilities(watchedFilesDynamicRegistration: false));
        $this->watcher->beforeMessage();
    }

    protected function tearDown(): void
    {
        self::remove($this->root);
    }

    public function testTheFirstLookReportsNothing(): void
    {
        self::assertSame([], $this->invalidated, 'what is there when watching starts is not a change');
    }

    public function testNothingChangedReportsNothing(): void
    {
        $this->later();

        self::assertSame([], $this->invalidated, 'an unchanged tree produces no events');
    }

    public function testAWatchedFileThatChangedIsReported(): void
    {
        touch($this->root . '/bootstrap.php', self::LONG_AGO + 500);

        $this->later();

        self::assertSame([$this->uri('bootstrap.php')], $this->invalidated, 'a changed file is one event for it');
    }

    public function testAWatchedFileThatWasDeletedIsReported(): void
    {
        unlink($this->root . '/bootstrap.php');

        $this->later();

        self::assertSame([$this->uri('bootstrap.php')], $this->invalidated, 'a deleted file is one event for it');
    }

    public function testAWatchedFileThatAppearsIsReported(): void
    {
        $this->watched = $this->watched->with(new WatchedPaths(files: [$this->root . '/late.php']));
        $this->later();
        self::assertSame([], $this->invalidated, 'a file newly watched, and absent, is a baseline and not a change');

        $this->place('late.php');
        $this->later();

        self::assertSame([$this->uri('late.php')], $this->invalidated, 'a created file is one event for that file');
    }

    public function testAFileAddedUnderARootIsReported(): void
    {
        $this->place('src/Sprocket.php');
        touch($this->root . '/src', self::LONG_AGO + 500);

        $this->later();

        self::assertSame([$this->uri('src/Sprocket.php')], $this->invalidated, 'only the new file is reported');
    }

    public function testAFileRemovedUnderARootIsReported(): void
    {
        unlink($this->root . '/src/Nested/Gadget.php');
        touch($this->root . '/src/Nested', self::LONG_AGO + 500);

        $this->later();

        self::assertSame(
            [$this->uri('src/Nested/Gadget.php')],
            $this->invalidated,
            'a directory beneath the root is watched as the root is',
        );
    }

    public function testAChangeToAFilesContentUnderARootIsNotReported(): void
    {
        touch($this->root . '/src/Widget.php', self::LONG_AGO + 500);

        $this->later();

        self::assertSame([], $this->invalidated, 'under a root only a file\'s existence is watched');
    }

    public function testAnEntryThatIsNotPhpIsNotReported(): void
    {
        copy($this->fixturePath('composer.json'), $this->root . '/src/.Widget.php.swp');
        touch($this->root . '/src', self::LONG_AGO + 500);

        $this->later();

        self::assertSame([], $this->invalidated, 'an editor\'s swap file changes the directory and no PHP file');
    }

    public function testADirectoryAddedUnderARootIsWatchedFromThenOn(): void
    {
        mkdir($this->root . '/src/Fresh');
        $this->place('src/Fresh/First.php');
        $this->settle();
        touch($this->root . '/src', self::LONG_AGO + 500);

        $this->later();
        self::assertSame([$this->uri('src/Fresh/First.php')], $this->invalidated, 'a new directory\'s files are new');

        $this->invalidated = [];
        $this->place('src/Fresh/Second.php');
        touch($this->root . '/src/Fresh', self::LONG_AGO + 700);
        $this->later();

        self::assertSame(
            [$this->uri('src/Fresh/Second.php')],
            $this->invalidated,
            'the new directory is watched like any other',
        );
    }

    public function testADirectoryRemovedUnderARootReportsItsFiles(): void
    {
        unlink($this->root . '/src/Nested/Gadget.php');
        rmdir($this->root . '/src/Nested');
        touch($this->root . '/src', self::LONG_AGO + 500);

        $this->later();

        self::assertSame([$this->uri('src/Nested/Gadget.php')], $this->invalidated, 'its files went with it');
    }

    public function testARootThatDoesNotExistYetIsSeenWhenItAppears(): void
    {
        $this->watched = new WatchedPaths(roots: [$this->root . '/lib']);
        $this->later();

        mkdir($this->root . '/lib');
        $this->place('lib/Arrival.php');
        touch($this->root . '/lib', self::LONG_AGO + 500);
        $this->later();

        self::assertSame(
            [$this->uri('lib/Arrival.php')],
            $this->invalidated,
            'Composer maps a prefix to a directory whether or not the directory exists yet',
        );
    }

    public function testARootThatDisappearsIsSeenAgainWhenItReturns(): void
    {
        $this->watched = new WatchedPaths(roots: [$this->root . '/src/Nested']);
        $this->later();

        unlink($this->root . '/src/Nested/Gadget.php');
        rmdir($this->root . '/src/Nested');
        $this->later();
        self::assertSame([$this->uri('src/Nested/Gadget.php')], $this->invalidated, 'the files went with the root');

        $this->invalidated = [];
        mkdir($this->root . '/src/Nested');
        $this->place('src/Nested/Gadget.php');
        touch($this->root . '/src/Nested', self::LONG_AGO + 500);
        $this->later();

        self::assertSame([$this->uri('src/Nested/Gadget.php')], $this->invalidated, 'a branch checkout can do this');
    }

    public function testAChangeInTheSameSecondAsTheLastLookIsStillSeen(): void
    {
        // The filesystem reports whole seconds. A directory last looked at during
        // the second it was modified may have been modified again since.
        touch($this->root . '/src', $this->now);
        $this->watcher->beforeMessage();
        $this->invalidated = [];

        $this->place('src/Sprocket.php');
        touch($this->root . '/src', $this->now);
        $this->watcher->beforeMessage();

        self::assertSame(
            [$this->uri('src/Sprocket.php')],
            $this->invalidated,
            'an unchanged modification time proves nothing within its own second',
        );
    }

    public function testARootNoLongerWatchedIsForgotten(): void
    {
        $this->watched = new WatchedPaths();
        $this->later();

        $this->place('src/Sprocket.php');
        touch($this->root . '/src', self::LONG_AGO + 500);
        unlink($this->root . '/bootstrap.php');
        $this->later();

        self::assertSame([], $this->invalidated, 'what no holder needs watched produces no events');
    }

    public function testAClientThatReportsChangesItselfIsNotSecondGuessed(): void
    {
        $this->watcher->onInitialized(new SessionCapabilities(watchedFilesDynamicRegistration: true));
        touch($this->root . '/bootstrap.php', self::LONG_AGO + 500);

        $this->later();

        self::assertSame(
            [],
            $this->invalidated,
            'workspace/didChangeWatchedFiles is the route; this stands in only where a client lacks it',
        );
    }

    private function later(): void
    {
        $this->now += 10;
        $this->watcher->beforeMessage();
    }

    private function place(string $relative): void
    {
        self::assertTrue(
            copy($this->fixturePath('src/Domain/User.php'), $this->root . '/' . $relative),
            "{$relative} must be writable",
        );
        touch($this->root . '/' . $relative, self::LONG_AGO);
    }

    /**
     * Writing into a directory stamps it with the real time; put every
     * directory back to a time well before the test's clock.
     */
    private function settle(): void
    {
        foreach ((new PhpDirectoryReader())->walk($this->root) as $listing) {
            touch($listing->path, self::LONG_AGO);
        }
    }

    private function uri(string $relative): string
    {
        return FileUri::fromPath($this->root . '/' . $relative);
    }

    private static function remove(string $directory): void
    {
        $entries = scandir($directory);
        foreach ($entries === false ? [] : array_diff($entries, ['.', '..']) as $entry) {
            $path = $directory . '/' . $entry;
            is_dir($path) ? self::remove($path) : unlink($path);
        }
        rmdir($directory);
    }
}
