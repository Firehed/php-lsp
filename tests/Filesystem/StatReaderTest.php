<?php

declare(strict_types=1);

namespace Firehed\PhpLsp\Tests\Filesystem;

use Firehed\PhpLsp\Filesystem\PathStamp;
use Firehed\PhpLsp\Filesystem\StatReader;
use Firehed\PhpLsp\Tests\LoadsFixturesTrait;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(StatReader::class)]
#[CoversClass(PathStamp::class)]
final class StatReaderTest extends TestCase
{
    use LoadsFixturesTrait;

    private string $path;

    protected function setUp(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'php-lsp-stat-');
        self::assertNotFalse($path, 'a temp file must be obtainable');
        $this->path = $path;
    }

    protected function tearDown(): void
    {
        @unlink($this->path);
    }

    public function testAStampCarriesTheModificationTimeAndSize(): void
    {
        copy($this->fixturePath('src/Domain/User.php'), $this->path);
        touch($this->path, 1_000_000);

        $stamp = (new StatReader())->stamp($this->path);

        self::assertNotNull($stamp, 'a file that exists has a stamp');
        self::assertSame(1_000_000, $stamp->modifiedAt, 'the stamp carries when the path last changed');
        self::assertSame(filesize($this->fixturePath('src/Domain/User.php')), $stamp->size, 'and how large it is');
    }

    public function testAChangeMadeAfterAnEarlierReadIsSeen(): void
    {
        $reader = new StatReader();
        touch($this->path, 1_000_000);
        $reader->stamp($this->path);

        touch($this->path, 2_000_000);

        self::assertSame(
            2_000_000,
            $reader->stamp($this->path)?->modifiedAt,
            'PHP remembers stat results within a process; a stamp must not be served from that memory',
        );
    }

    public function testAPathThatDoesNotExistHasNoStamp(): void
    {
        unlink($this->path);

        self::assertNull((new StatReader())->stamp($this->path), 'absence is an answer, not an error');
    }
}
