<?php

declare(strict_types=1);

namespace Firehed\PhpLsp\Tests\Index;

use Firehed\PhpLsp\Index\ComposerClassLocator;
use Firehed\PhpLsp\Tests\Fixtures\Autoload\ClassmapFixture;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(ComposerClassLocator::class)]
final class ComposerClassLocatorTest extends TestCase
{
    private const PROJECT_ROOT = __DIR__ . '/../..';

    public function testLocateClassFromClassmap(): void
    {
        $locator = new ComposerClassLocator(self::PROJECT_ROOT);

        $path = $locator->locateClass(ClassmapFixture::class);

        self::assertNotNull($path);
        self::assertStringEndsWith('tests/Fixtures/Autoload/Classmap/ClassmapFixture.php', $path);
    }

    public function testLocateClassReturnsNullForNonexistentClass(): void
    {
        $locator = new ComposerClassLocator(self::PROJECT_ROOT);

        $path = $locator->locateClass('NonExistent\\Class');

        self::assertNull($path);
    }

    public function testLocateClassFromPsr4(): void
    {
        $locator = new ComposerClassLocator(self::PROJECT_ROOT);

        $path = $locator->locateClass(ComposerClassLocator::class);

        self::assertNotNull($path);
        self::assertStringEndsWith('src/Index/ComposerClassLocator.php', $path);
    }
}
