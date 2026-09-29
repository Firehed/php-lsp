<?php

declare(strict_types=1);

namespace Firehed\PhpLsp\Tests\Filesystem;

use Firehed\PhpLsp\Filesystem\DirectoryListing;
use Firehed\PhpLsp\Filesystem\PhpDirectoryReader;
use Firehed\PhpLsp\Tests\LoadsFixturesTrait;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(PhpDirectoryReader::class)]
#[CoversClass(DirectoryListing::class)]
final class PhpDirectoryReaderTest extends TestCase
{
    use LoadsFixturesTrait;

    public function testReadListsPhpFilesAndDirectoriesOneLevelDown(): void
    {
        $root = rtrim($this->fixturePath(''), '/');

        $listing = (new PhpDirectoryReader())->read($root);

        self::assertNotNull($listing, 'the fixtures root is a directory');
        self::assertSame($root, $listing->path, 'a listing names the directory it describes');
        self::assertContains($root . '/NoNamespace.php', $listing->files, 'a PHP file is listed by its full path');
        self::assertNotContains($root . '/composer.json', $listing->files, 'only PHP files are listed');
        self::assertContains($root . '/src', $listing->directories, 'a directory is listed by its full path');
        self::assertNotContains(
            $root . '/src/Domain/User.php',
            $listing->files,
            'a listing describes one directory, not what is beneath it',
        );
    }

    public function testReadOfSomethingThatIsNotADirectoryIsNull(): void
    {
        $reader = new PhpDirectoryReader();

        self::assertNull($reader->read($this->fixturePath('no/such/directory')), 'a missing path has no listing');
        self::assertNull($reader->read($this->fixturePath('NoNamespace.php')), 'a file has no listing');
    }

    public function testWalkReachesEveryDirectoryBeneathTheRoot(): void
    {
        $root = $this->fixturePath('src');

        $paths = [];
        $files = [];
        foreach ((new PhpDirectoryReader())->walk($root) as $listing) {
            $paths[] = $listing->path;
            $files = [...$files, ...$listing->files];
        }

        self::assertSame($root, $paths[0], 'the walk starts with the root itself');
        self::assertContains($root . '/Domain', $paths, 'a directory beneath the root is walked');
        self::assertContains($root . '/Domain/User.php', $files, 'a file in a nested directory is reached');
        self::assertSame(array_unique($paths), $paths, 'no directory is walked twice');
    }

    public function testWalkOfSomethingThatIsNotADirectoryIsEmpty(): void
    {
        self::assertSame(
            [],
            iterator_to_array((new PhpDirectoryReader())->walk($this->fixturePath('no/such/directory')), false),
            'an autoload root that does not exist contributes nothing',
        );
    }
}
