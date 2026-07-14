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
}
