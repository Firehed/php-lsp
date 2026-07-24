<?php

declare(strict_types=1);

namespace Firehed\PhpLsp\Tests\Parity;

use PHPUnit\Framework\AssertionFailedError;
use PHPUnit\Framework\TestCase;

/**
 * The harness is only trustworthy if a diverged surface actually goes red. This
 * exercises {@see AssertsGolden} against a scratch golden directory so the "fails
 * on any diff" and "captures in update mode" behaviors are proven, not assumed.
 */
final class AssertsGoldenTest extends TestCase
{
    use AssertsGolden;

    private string $scratchDir;

    protected function setUp(): void
    {
        $dir = sys_get_temp_dir() . '/php-lsp-golden-' . bin2hex(random_bytes(6));
        mkdir($dir);
        $this->scratchDir = $dir;
    }

    protected function tearDown(): void
    {
        $files = glob($this->scratchDir . '/*');
        foreach ($files === false ? [] : $files as $file) {
            unlink($file);
        }
        rmdir($this->scratchDir);
    }

    protected function goldenDir(): string
    {
        return $this->scratchDir;
    }

    public function testUpdateModeWritesTheGolden(): void
    {
        $this->updateGoldens = true;

        $this->assertGoldenMatches('sample', ['value' => 1]);

        self::assertSame(
            GoldenCodec::encode(['value' => 1]),
            file_get_contents($this->scratchDir . '/sample.json'),
            'update mode must write the captured output verbatim to the golden file',
        );
    }

    public function testMatchingCapturePasses(): void
    {
        $this->updateGoldens = true;
        $this->assertGoldenMatches('sample', ['value' => 1]);

        $this->updateGoldens = false;
        $this->assertGoldenMatches('sample', ['value' => 1]);
    }

    public function testDivergentCaptureFails(): void
    {
        $this->updateGoldens = true;
        $this->assertGoldenMatches('sample', ['value' => 1]);
        $this->updateGoldens = false;

        $this->expectException(AssertionFailedError::class);
        $this->assertGoldenMatches('sample', ['value' => 2]);
    }

    public function testMissingGoldenFails(): void
    {
        $this->expectException(AssertionFailedError::class);
        $this->assertGoldenMatches('never-captured', ['value' => 1]);
    }
}
