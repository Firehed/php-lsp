<?php

declare(strict_types=1);

namespace Firehed\PhpLsp\Tests\Integration;

use Firehed\PhpLsp\Domain\ClasslikeName;
use Firehed\PhpLsp\Index\ComposerAutoloadMap;
use Firehed\PhpLsp\Tests\BuildsKnowledgeStackTrait;
use Firehed\PhpLsp\Tests\Parser\ProductionSyntaxSource;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\TestCase;

/**
 * Name-to-file resolution follows Composer's own precedence, so the server
 * answers with the file the runtime would load. A classmap entry wins over a
 * PSR-4 directory even when the PSR-4 file is the project's own code and the
 * classmap file is a dependency's.
 */
#[CoversNothing]
final class AutoloadPrecedenceTest extends TestCase
{
    use BuildsKnowledgeStackTrait;

    public function testAClassmapEntryBeatsAPsr4DirectoryAsComposerDoes(): void
    {
        $root = dirname(__DIR__) . '/Fixtures/Precedence';
        $stack = $this->knowledgeStackForMap(
            new ComposerAutoloadMap(
                psr4: ['Prec\\' => [$root . '/src']],
                classMap: ['Prec\\Thing' => $root . '/vendor/Thing.php'],
            ),
            ProductionSyntaxSource::create(),
        );

        $info = $stack->source->lookupClassLike(ClasslikeName::fromFullyQualified('Prec\Thing'));

        self::assertNotNull($info, 'the class must resolve through one of the two routes');
        self::assertSame(
            $root . '/vendor/Thing.php',
            $info->file,
            'Composer consults the classmap before any PSR-4 directory, so the server must too',
        );
    }
}
