<?php

declare(strict_types=1);

namespace Firehed\PhpLsp\Tests\Knowledge;

use Firehed\PhpLsp\Cache\CacheFactory;
use Firehed\PhpLsp\Domain\ClasslikeName;
use Firehed\PhpLsp\Domain\ComposerAutoloadMap;
use Firehed\PhpLsp\Domain\FileUri;
use Firehed\PhpLsp\Knowledge\AutoloadFilesBackend;
use Firehed\PhpLsp\Knowledge\CachingSymbolSource;
use Firehed\PhpLsp\Knowledge\ComposerMapBackend;
use Firehed\PhpLsp\Knowledge\CompositeInvalidatable;
use Firehed\PhpLsp\Knowledge\DeclarationScanner;
use Firehed\PhpLsp\Knowledge\DeclarationSymbolInfoFactory;
use Firehed\PhpLsp\Knowledge\SymbolSourceInterface;
use Firehed\PhpLsp\Tests\BuildsSymbolInfoTrait;
use Firehed\PhpLsp\Tests\Parser\ProductionSyntaxSource;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(CompositeInvalidatable::class)]
final class CompositeInvalidatableTest extends TestCase
{
    use BuildsSymbolInfoTrait;

    public function testInvalidateReachesEveryNamedMember(): void
    {
        $file = '/workspace/src/Widget.php';
        $classInfo = self::classInfo('App\\Widget', file: $file);
        $inner = $this->createMock(SymbolSourceInterface::class);
        $inner->expects($this->exactly(2))
            ->method('lookupClassLike')
            ->willReturn($classInfo);

        $mapsDecorator = new CachingSymbolSource($inner, CacheFactory::inMemory());
        $mapsBackend = self::composerMapBackend();
        $filesBackend = self::autoloadFilesBackend();
        $composite = new CompositeInvalidatable($mapsDecorator, $mapsBackend, $filesBackend);

        $name = ClasslikeName::fromFullyQualified('App\\Widget');
        self::assertSame(
            $classInfo,
            $mapsDecorator->lookupClassLike($name),
            'first lookup populates the decorator cache from the inner source',
        );

        // Fans out to all three: the decorator drops its entry for this file, and
        // the two backends (which do not know this path) still receive the call.
        $composite->invalidate(FileUri::fromPath($file));

        self::assertSame(
            $classInfo,
            $mapsDecorator->lookupClassLike($name),
            'the decorator must consult the inner again — proving the fan-out reached it',
        );
    }

    private static function composerMapBackend(): ComposerMapBackend
    {
        $production = ProductionSyntaxSource::create();
        $map = ComposerAutoloadMap::fromProjectRoot(dirname(__DIR__) . '/Fixtures');

        return new ComposerMapBackend(
            $map,
            $production->source,
            $production->reader,
            new DeclarationSymbolInfoFactory(),
            new DeclarationScanner(),
        );
    }

    private static function autoloadFilesBackend(): AutoloadFilesBackend
    {
        $production = ProductionSyntaxSource::create();
        $map = ComposerAutoloadMap::fromProjectRoot(dirname(__DIR__) . '/Fixtures');

        return new AutoloadFilesBackend(
            $map,
            new DeclarationSymbolInfoFactory(),
            new DeclarationScanner(),
            $production->reader,
            $production->source,
        );
    }
}
