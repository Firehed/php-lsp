<?php

declare(strict_types=1);

namespace Firehed\PhpLsp\Tests\Parser\SyntaxSource;

use Firehed\PhpLsp\Document\TextDocument;
use Firehed\PhpLsp\Parser\SyntaxSource\CompositeSyntaxSource;
use Firehed\PhpLsp\Parser\SyntaxSource\MemoizingSyntaxSource;
use Firehed\PhpLsp\Parser\SyntaxSource\PhpParserSyntaxSource;
use Firehed\PhpLsp\Parser\SyntaxSource\SkeletonSyntaxSource;
use Firehed\PhpLsp\Parser\SyntaxSource\SyntaxSourceInterface;
use Firehed\PhpLsp\Parser\TreeAnnotator;
use Firehed\PhpLsp\Tests\LoadsFixturesTrait;
use Firehed\PhpLsp\Tests\Parser\AssertsTreeContractTrait;
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
     * Each source on every file it makes a tree of, and the memoized composite
     * over them once through each of its members.
     *
     * @return iterable<string, array{SyntaxSourceInterface, string}>
     */
    public static function treesFromEverySource(): iterable
    {
        $parser = new PhpParserSyntaxSource(new TreeAnnotator());
        $skeleton = new SkeletonSyntaxSource();
        $sources = [
            'php-parser' => [$parser, self::PARSEABLE],
            'skeleton' => [$skeleton, [...self::PARSEABLE, self::SKELETON_ONLY]],
            'memoized composite' => [
                new MemoizingSyntaxSource(new CompositeSyntaxSource($parser, $skeleton)),
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
