<?php

declare(strict_types=1);

namespace Firehed\PhpLsp\Tests\Index;

use Firehed\PhpLsp\Index\ComposerAutoloadMap;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(ComposerAutoloadMap::class)]
class ComposerAutoloadMapTest extends TestCase
{
    public function testMapsAreReadFromTheProject(): void
    {
        $map = ComposerAutoloadMap::fromProjectRoot(__DIR__ . '/../Fixtures');

        self::assertArrayHasKey('Fixtures\\', $map->psr4Prefixes(), 'The project\'s own PSR-4 prefix');
        self::assertArrayHasKey('Psr0', $map->psr0Prefixes(), 'The project\'s PSR-0 prefix');
        self::assertArrayHasKey('GlobalConfig', $map->classMap(), 'A classmapped class');
    }

    public function testAutoloadFilesAreReadFromTheProject(): void
    {
        $map = ComposerAutoloadMap::fromProjectRoot(__DIR__ . '/../Fixtures');

        $files = $map->autoloadFiles();

        self::assertCount(2, $files, 'The fixture project declares two autoload.files entries');
        foreach (['AutoloadFiles/globals.php', 'AutoloadFiles/helpers.php'] as $expected) {
            self::assertTrue(
                self::containsPathEndingIn($files, $expected),
                "autoload.files must report {$expected}, the bounded function/constant reach (Plan 0002 §3)",
            );
        }
    }

    public function testAutoloadFilesAreKeyedByPositionRatherThanComposersHashes(): void
    {
        $map = ComposerAutoloadMap::fromProjectRoot(__DIR__ . '/../Fixtures');

        self::assertSame(
            [0, 1],
            array_keys($map->autoloadFiles()),
            'Composer keys the generated file by a content hash; consumers want a plain list',
        );
    }

    public function testAProjectWithoutAutoloadFilesYieldsAnEmptyList(): void
    {
        // Composer generates no autoload_files.php when a project declares no `files`
        // autoload, so the read reaches a missing file — the same path a root with no
        // vendor/ at all takes. Either way it is absence, not an error.
        $map = ComposerAutoloadMap::fromProjectRoot('/nonexistent');

        self::assertSame([], $map->autoloadFiles(), 'A project with no files autoload is not an error');
    }

    public function testMalformedAutoloadFileEntriesAreDiscarded(): void
    {
        $map = ComposerAutoloadMap::fromProjectRoot(__DIR__ . '/../Fixtures/MalformedProject');

        self::assertSame(
            ['/tmp/valid-helpers.php'],
            $map->autoloadFiles(),
            'These files are generated, but they are still data from a project we do not control',
        );
    }

    public function testAProjectWithoutComposerYieldsEmptyMaps(): void
    {
        $map = ComposerAutoloadMap::fromProjectRoot('/nonexistent');

        self::assertSame([], $map->psr4Prefixes(), 'A project with no vendor/ is not an error');
        self::assertSame([], $map->psr0Prefixes(), 'A project with no vendor/ is not an error');
        self::assertSame([], $map->classMap(), 'A project with no vendor/ is not an error');
    }

    public function testMalformedEntriesAreDiscarded(): void
    {
        $map = ComposerAutoloadMap::fromProjectRoot(__DIR__ . '/../Fixtures/MalformedProject');

        self::assertSame(
            ['Valid\\' => ['/tmp/valid']],
            $map->psr4Prefixes(),
            'These files are generated, but they are still data from a project we do not control',
        );
    }

    public function testARootNamespaceMappingIsExposedAsTheEmptyPrefix(): void
    {
        $map = new ComposerAutoloadMap(psr4: ['' => ['/app/src']]);

        self::assertSame(
            ['' => ['/app/src']],
            $map->psr4Prefixes(),
            'Composer routes a root-namespace mapping to a fallback dir; enumeration needs it as the empty prefix',
        );
    }

    /**
     * @param list<string> $paths
     */
    private static function containsPathEndingIn(array $paths, string $suffix): bool
    {
        foreach ($paths as $path) {
            if (str_ends_with($path, $suffix)) {
                return true;
            }
        }

        return false;
    }
}
