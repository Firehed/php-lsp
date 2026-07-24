<?php

declare(strict_types=1);

namespace Firehed\PhpLsp\Tests\Parity;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

#[CoversClass(GoldenCodec::class)]
final class GoldenCodecTest extends TestCase
{
    public function testEncodeProducesPrettyJsonWithTrailingNewline(): void
    {
        $encoded = GoldenCodec::encode(['b' => 1, 'a' => [2, 3]]);

        self::assertSame(
            "{\n    \"b\": 1,\n    \"a\": [\n        2,\n        3\n    ]\n}\n",
            $encoded,
            'goldens are stored as pretty JSON with a trailing newline so they diff line by line',
        );
    }

    public function testEncodePreservesCallerOrderingRatherThanSorting(): void
    {
        $encoded = GoldenCodec::encode(['z' => 1, 'a' => 2]);

        self::assertStringContainsString(
            "\"z\": 1",
            $encoded,
            'ordering is the capturer\'s responsibility; the codec must not reorder keys',
        );
        self::assertLessThan(
            strpos($encoded, '"a": 2'),
            strpos($encoded, '"z": 1'),
            'the codec must emit keys in the order given, not sorted',
        );
    }

    public function testEncodeLeavesSlashesAndUnicodeUnescaped(): void
    {
        $encoded = GoldenCodec::encode(['path' => 'src/Foo.php', 'name' => 'Frĥ']);

        self::assertStringContainsString('src/Foo.php', $encoded, 'slashes must not be escaped');
        self::assertStringContainsString('Frĥ', $encoded, 'unicode must not be escaped');
    }

    /**
     * @return array<string, array{string, string, string}>
     * @codeCoverageIgnore
     */
    public static function pathCases(): array
    {
        return [
            'strips project root prefix' => ['/repo/root/src/Foo.php', '/repo/root', 'src/Foo.php'],
            'strips file scheme then root' => ['file:///repo/root/tests/Bar.php', '/repo/root', 'tests/Bar.php'],
            'tolerates trailing slash on root' => ['/repo/root/src/Foo.php', '/repo/root/', 'src/Foo.php'],
            'leaves an outside path unchanged' => ['/elsewhere/Baz.php', '/repo/root', '/elsewhere/Baz.php'],
            'strips scheme even when outside root' => ['file:///elsewhere/Baz.php', '/repo/root', '/elsewhere/Baz.php'],
        ];
    }

    #[DataProvider('pathCases')]
    public function testRelativizePath(string $path, string $root, string $expected): void
    {
        self::assertSame(
            $expected,
            GoldenCodec::relativizePath($path, $root),
            'a golden must store a portable, root-relative path, not the machine it was captured on',
        );
    }
}
