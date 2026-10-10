<?php

declare(strict_types=1);

namespace Firehed\PhpLsp\Tests\Parser\SyntaxSource;

use Firehed\PhpLsp\Document\TextDocument;
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

    private const array FIXTURES = [
        'src/Domain/User.php',
        'src/Inheritance/ChildClass.php',
        'src/Exception/AppException.php',
        'src/Hierarchy/LeafInterface.php',
        'src/Resolution/CursorTextResolution.php',
        'src/IncompleteCode/AliasedImports.php',
        'src/IncompleteCode/GroupImports.php',
        'src/IncompleteCode/VeryBroken.php',
        'src/TypeInference/BuiltinTypes.php',
        'SignatureHelp.php',
    ];

    /**
     * @return iterable<string, array{SyntaxSourceInterface, string}>
     */
    public static function treesFromEverySource(): iterable
    {
        $sources = [
            'php-parser' => new PhpParserSyntaxSource(new TreeAnnotator()),
            'skeleton' => new SkeletonSyntaxSource(),
        ];
        foreach ($sources as $sourceName => $source) {
            foreach (self::FIXTURES as $fixture) {
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
        if ($parsed->tree === []) {
            self::markTestSkipped("{$fixture} yields no tree from this source");
        }
        self::assertTreeMeetsContract($parsed->tree, $fixture);
    }
}
