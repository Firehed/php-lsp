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

    public function testLocateClassFromVendorClassmap(): void
    {
        $locator = new ComposerClassLocator(self::PROJECT_ROOT);

        $path = $locator->locateClass(TestCase::class);

        self::assertNotNull($path);
        self::assertStringContainsString('phpunit/phpunit', $path);
    }

    public function testDoesNotRegisterAutoloader(): void
    {
        $autoloadersBefore = spl_autoload_functions();

        new ComposerClassLocator(self::PROJECT_ROOT);

        $autoloadersAfter = spl_autoload_functions();

        self::assertSame(
            count($autoloadersBefore),
            count($autoloadersAfter),
            'ComposerClassLocator should not register additional autoloaders',
        );
    }

    public function testMissingComposerDirectoryReturnsNull(): void
    {
        $locator = new ComposerClassLocator('/nonexistent/path');

        $path = $locator->locateClass(TestCase::class);

        self::assertNull($path);
    }
}
