<?php

declare(strict_types=1);

namespace Firehed\PhpLsp\Tests\Parser;

use Firehed\PhpLsp\Document\SourceFileReader;
use Firehed\PhpLsp\Knowledge\DeclarationScanner;
use Firehed\PhpLsp\Knowledge\ParsedDeclarationSource;
use Firehed\PhpLsp\Parser\Recovery\CompositeRecovery;
use Firehed\PhpLsp\Parser\Recovery\EndOfFileRecovery;
use Firehed\PhpLsp\Parser\Recovery\GapGraftingRecovery;
use Firehed\PhpLsp\Parser\Recovery\SkeletonRecovery;
use Firehed\PhpLsp\Parser\SyntaxSource\MemoizingSyntaxSource;
use Firehed\PhpLsp\Parser\SyntaxSource\ParsingSyntaxSource;
use Firehed\PhpLsp\Parser\SyntaxSource\PhpParserCodeParser;
use Firehed\PhpLsp\Parser\SyntaxSource\SyntaxSourceInterface;
use Firehed\PhpLsp\Parser\TreeAnnotator;

/**
 * The test-facing wiring for the production SyntaxSourceInterface stack. Mirrors what `config/services.php` assembles,
 * so a test that needs production wiring does not name any implementation
 * directly.
 *
 * The exposed {@see CountingSyntaxSource} sits under the memoizer, so tests can
 * assert how many times the real parser actually ran rather than how many times
 * the memoizer was consulted.
 */
final readonly class ProductionSyntaxSource
{
    public MemoizingSyntaxSource $source;
    public CountingSyntaxSource $counter;
    public SourceFileReader $reader;
    public ParsedDeclarationSource $declarations;

    private function __construct()
    {
        $this->counter = new CountingSyntaxSource(self::unmemoized());
        $this->source = new MemoizingSyntaxSource($this->counter);
        $this->reader = new SourceFileReader();
        $this->declarations = new ParsedDeclarationSource(
            $this->source,
            new DeclarationScanner(),
        );
    }

    public static function create(): self
    {
        return new self();
    }

    /**
     * The production syntax source alone, with every recovery step but no memo.
     */
    public static function unmemoized(): SyntaxSourceInterface
    {
        $parser = new PhpParserCodeParser();
        return new ParsingSyntaxSource(
            $parser,
            new CompositeRecovery(
                new EndOfFileRecovery($parser),
                new SkeletonRecovery(),
                new GapGraftingRecovery($parser),
            ),
            new TreeAnnotator(),
        );
    }
}
