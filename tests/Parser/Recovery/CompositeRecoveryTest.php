<?php

declare(strict_types=1);

namespace Firehed\PhpLsp\Tests\Parser\Recovery;

use Firehed\PhpLsp\Parser\Recovery\CompositeRecovery;
use Firehed\PhpLsp\Parser\Recovery\EndOfFileRecovery;
use Firehed\PhpLsp\Parser\Recovery\GapGraftingRecovery;
use Firehed\PhpLsp\Parser\Recovery\SkeletonRecovery;
use Firehed\PhpLsp\Parser\SyntaxSource\PhpParserCodeParser;
use Firehed\PhpLsp\Tests\LoadsFixturesTrait;
use PhpParser\Node\Expr\PropertyFetch;
use PhpParser\Node\Stmt\Namespace_;
use PhpParser\NodeFinder;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(CompositeRecovery::class)]
final class CompositeRecoveryTest extends TestCase
{
    use LoadsFixturesTrait;

    public function testTheMostFaithfulStepThatReadsTheCodeWins(): void
    {
        $tree = $this->recover('Recovery/EndsInMemberAccess.php');

        self::assertNotNull(
            (new NodeFinder())->findFirstInstanceOf($tree, PropertyFetch::class),
            'finishing the file reads the expression being typed, which the skeleton never builds',
        );
    }

    public function testTheLastResortReadsWhatNoEarlierStepCould(): void
    {
        $tree = $this->recover('Recovery/EndsAtClassKeyword.php');

        self::assertNotNull(
            (new NodeFinder())->findFirstInstanceOf($tree, Namespace_::class),
            'the skeleton still reads the namespace of code nothing else could finish',
        );
    }

    /**
     * @return array<\PhpParser\Node\Stmt>
     */
    private function recover(string $fixture): array
    {
        $code = $this->loadFixture($fixture);
        $parser = new PhpParserCodeParser();
        $recovery = new CompositeRecovery(
            new EndOfFileRecovery($parser),
            new SkeletonRecovery(),
            new GapGraftingRecovery($parser),
        );
        return $recovery->recover($code, $parser->parse($code))->tree;
    }
}
