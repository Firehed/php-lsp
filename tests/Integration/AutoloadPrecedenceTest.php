<?php

declare(strict_types=1);

namespace Firehed\PhpLsp\Tests\Integration;

use Firehed\PhpLsp\Domain\ClasslikeName;
use Firehed\PhpLsp\Domain\ComposerAutoloadMap;
use Firehed\PhpLsp\Knowledge\KnowledgeStack;
use Firehed\PhpLsp\Tests\BuildsKnowledgeStackTrait;
use Firehed\PhpLsp\Tests\Parser\ProductionSyntaxSource;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\TestCase;

/**
 * Name-to-file resolution follows Composer's own precedence, so the server
 * answers with the file the runtime would load, whichever route declares it.
 */
#[CoversNothing]
final class AutoloadPrecedenceTest extends TestCase
{
    use BuildsKnowledgeStackTrait;

    private const string ROOT = __DIR__ . '/../Fixtures/Precedence';

    public function testAClassmapEntryBeatsAPsr4DirectoryAsComposerDoes(): void
    {
        $stack = $this->stackFor(new ComposerAutoloadMap(
            psr4: ['Prec\\' => [self::ROOT . '/src']],
            classMap: ['Prec\\Thing' => self::ROOT . '/vendor/Thing.php'],
        ));

        $info = $stack->source->lookupClassLike(ClasslikeName::fromFullyQualified('Prec\Thing'));

        self::assertNotNull($info, 'the class must resolve through one of the two routes');
        self::assertSame(
            self::ROOT . '/vendor/Thing.php',
            $info->file,
            'Composer consults the classmap before any PSR-4 directory, so the server must too',
        );
    }

    public function testAFilesEntryBeatsAPsr4DirectoryAsComposerDoes(): void
    {
        $stack = $this->stackFor(new ComposerAutoloadMap(
            psr4: ['Prec\\' => [self::ROOT . '/src']],
            files: [self::ROOT . '/bootstrap.php'],
        ));

        $info = $stack->source->lookupClassLike(ClasslikeName::fromFullyQualified('Prec\Bootstrapped'));

        self::assertNotNull($info, 'the class must resolve through one of the two routes');
        self::assertSame(
            self::ROOT . '/bootstrap.php',
            $info->file,
            'Composer requires every files entry before the autoloader is ever asked, so a declaration there wins',
        );
    }

    private function stackFor(ComposerAutoloadMap $map): KnowledgeStack
    {
        return $this->knowledgeStackForMap($map, ProductionSyntaxSource::create());
    }
}
