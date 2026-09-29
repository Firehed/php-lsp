<?php

declare(strict_types=1);

namespace Firehed\PhpLsp\Tests\Knowledge;

use Firehed\PhpLsp\Domain\ComposerAutoloadMap;
use Firehed\PhpLsp\Domain\NameKind;
use Firehed\PhpLsp\Filesystem\PhpDirectoryReader;
use Firehed\PhpLsp\Knowledge\AutoloadFilesBackend;
use Firehed\PhpLsp\Knowledge\ComposerAutoloadMapReader;
use Firehed\PhpLsp\Knowledge\ComposerMapBackend;
use Firehed\PhpLsp\Knowledge\CompositeWatchedPaths;
use Firehed\PhpLsp\Tests\LoadsFixturesTrait;
use Firehed\PhpLsp\Tests\Parser\ProductionSyntaxSource;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(CompositeWatchedPaths::class)]
final class CompositeWatchedPathsTest extends TestCase
{
    use LoadsFixturesTrait;

    public function testItWatchesWhatEveryMemberNeeds(): void
    {
        $root = $this->fixturePath('src');
        $entry = $this->fixturePath('src/Catalog/functions.php');
        $mapReader = ComposerAutoloadMapReader::fromMap(new ComposerAutoloadMap(
            psr4: ['Fixtures\\' => [$root]],
            files: [$entry],
        ));
        $production = ProductionSyntaxSource::create();
        $maps = new ComposerMapBackend(
            $mapReader,
            $production->reader,
            $production->declarations,
            new PhpDirectoryReader(),
        );
        $maps->search('User', NameKind::ClassLike);

        $watched = new CompositeWatchedPaths(
            $mapReader,
            $maps,
            new AutoloadFilesBackend($mapReader, $production->reader, $production->declarations),
        )->watchedPaths();

        self::assertContains($root, $watched->roots, 'the name list needs its autoload roots watched');
        self::assertContains($entry, $watched->files, 'the files index needs its entries watched');
        self::assertContains(
            '/vendor/composer/autoload_psr4.php',
            $watched->files,
            'the map reader needs Composer\'s generated files watched',
        );
    }
}
