<?php

declare(strict_types=1);

namespace Firehed\PhpLsp\Tests\Index;

use Firehed\PhpLsp\Index\ComposerAutoloadMap;
use Firehed\PhpLsp\Tests\Fixtures\Autoload\ClassmapFixture;
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

    public function testTheLoaderResolvesAClassToItsFile(): void
    {
        $map = ComposerAutoloadMap::fromProjectRoot(__DIR__ . '/../Fixtures');

        // @phpstan-ignore class.notFound
        $file = $map->classLoader()->findFile(ClassmapFixture::class);

        self::assertIsString($file, 'The map holds the same ClassLoader Composer uses for name -> file lookup');
        self::assertStringEndsWith('Fixtures/Autoload/Classmap/ClassmapFixture.php', $file);
    }

    public function testPartitionSplitsAutoloadTargetsByVendorDirectory(): void
    {
        $map = new ComposerAutoloadMap(
            psr4: [
                'App\\' => ['/project/src'],
                'Dep\\' => ['/project/vendor/dep/src'],
            ],
            psr0: [
                'Legacy_' => ['/project/vendor/legacy'],
            ],
            classMap: [
                'App\\Thing' => '/project/src/Thing.php',
                'Dep\\Widget' => '/project/vendor/dep/Widget.php',
            ],
        );

        [$workspace, $vendor] = $map->partitionByVendorDirectory('/project/vendor');

        self::assertSame(
            ['App\\' => ['/project/src']],
            $workspace->psr4Prefixes(),
            'the workspace half keeps only the project\'s own PSR-4 directories',
        );
        self::assertSame([], $workspace->psr0Prefixes(), 'the workspace half has no vendored PSR-0 prefix');
        self::assertSame(
            ['App\\Thing' => '/project/src/Thing.php'],
            $workspace->classMap(),
            'the workspace half keeps only classmap entries whose file is outside vendor/',
        );

        self::assertSame(
            ['Dep\\' => ['/project/vendor/dep/src']],
            $vendor->psr4Prefixes(),
            'the vendor half keeps the dependencies\' PSR-4 directories',
        );
        self::assertSame(
            ['Legacy_' => ['/project/vendor/legacy']],
            $vendor->psr0Prefixes(),
            'the vendor half keeps the vendored PSR-0 prefix',
        );
        self::assertSame(
            ['Dep\\Widget' => '/project/vendor/dep/Widget.php'],
            $vendor->classMap(),
            'the vendor half keeps classmap entries whose file is under vendor/',
        );
    }

    public function testPartitionSplitsAutoloadFilesByVendorDirectory(): void
    {
        $map = new ComposerAutoloadMap(
            files: [
                '/project/src/helpers.php',
                '/project/vendor/dep/src/functions.php',
            ],
        );

        [$workspace, $vendor] = $map->partitionByVendorDirectory('/project/vendor');

        self::assertSame(
            ['/project/src/helpers.php'],
            $workspace->autoloadFiles(),
            'the workspace half keeps only files outside vendor/',
        );
        self::assertSame(
            ['/project/vendor/dep/src/functions.php'],
            $vendor->autoloadFiles(),
            'the vendor half keeps only files under vendor/',
        );
    }

    public function testPartitionAssignsAPrefixWithMixedDirectoriesToBothHalves(): void
    {
        $map = new ComposerAutoloadMap(
            psr4: ['Shared\\' => ['/project/lib', '/project/vendor/shared/src']],
        );

        [$workspace, $vendor] = $map->partitionByVendorDirectory('/project/vendor');

        self::assertSame(
            ['Shared\\' => ['/project/lib']],
            $workspace->psr4Prefixes(),
            'a prefix mapping to both halves keeps its non-vendor directory in the workspace half',
        );
        self::assertSame(
            ['Shared\\' => ['/project/vendor/shared/src']],
            $vendor->psr4Prefixes(),
            'a prefix mapping to both halves keeps its vendor directory in the vendor half',
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
