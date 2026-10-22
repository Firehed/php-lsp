<?php

declare(strict_types=1);

namespace Firehed\PhpLsp\Tests\Parser\SyntaxSource;

use Firehed\PhpLsp\Document\TextDocument;
use Firehed\PhpLsp\Parser\SyntaxSource\SyntaxSourceInterface;
use Firehed\PhpLsp\Tests\LoadsFixturesTrait;
use Firehed\PhpLsp\Tests\Parser\AssertsTreeContractTrait;
use Firehed\PhpLsp\Tests\Parser\ParserOnlySyntaxSource;
use Firehed\PhpLsp\Tests\Parser\ProductionSyntaxSource;
use Firehed\PhpLsp\Tests\Parser\SkeletonOnlySyntaxSource;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Every tree source meets the guarantees ParsedDocument states, checked the
 * same way for each.
 */
#[CoversNothing]
final class SyntaxSourceContractTest extends TestCase
{
    use AssertsTreeContractTrait;
    use LoadsFixturesTrait;

    /**
     * Files every source makes a tree of.
     */
    private const array PARSEABLE = [
        'src/Domain/User.php',
        'src/Inheritance/ChildClass.php',
        'src/Exception/AppException.php',
        'src/Hierarchy/LeafInterface.php',
        'src/Resolution/CursorTextResolution.php',
        'src/IncompleteCode/AliasedImports.php',
        'src/IncompleteCode/GroupImports.php',
        'src/TypeInference/BuiltinTypes.php',
        'SignatureHelp.php',
    ];

    /**
     * A file php-parser makes no tree of, so only the skeleton reads it.
     */
    private const string SKELETON_ONLY = 'src/IncompleteCode/VeryBroken.php';

    /**
     * Each reader on every file it makes a tree of, and the memoized
     * production source over a file php-parser reads and one it does not.
     *
     * @return iterable<string, array{SyntaxSourceInterface, string}>
     */
    public static function treesFromEverySource(): iterable
    {
        $sources = [
            'php-parser' => [ParserOnlySyntaxSource::create(), self::PARSEABLE],
            'skeleton' => [new SkeletonOnlySyntaxSource(), [...self::PARSEABLE, self::SKELETON_ONLY]],
            'memoized production' => [
                ProductionSyntaxSource::create()->source,
                [self::PARSEABLE[0], self::SKELETON_ONLY],
            ],
        ];
        foreach ($sources as $sourceName => [$source, $fixtures]) {
            foreach ($fixtures as $fixture) {
                yield "{$sourceName}: {$fixture}" => [$source, $fixture];
            }
        }
    }

    #[DataProvider('treesFromEverySource')]
    public function testTheTreeMeetsTheContract(SyntaxSourceInterface $source, string $fixture): void
    {
        $document = new TextDocument('file:///' . $fixture, 'php', 1, $this->loadFixture($fixture));
        $parsed = $source->parse($document);

        self::assertSame($document, $parsed->document, 'the tree is paired with the document it was parsed from');
        self::assertTreeMeetsContract($parsed->tree, $fixture);
    }
}
