<?php

declare(strict_types=1);

namespace Firehed\PhpLsp\Tests\Parser;

use Firehed\PhpLsp\Document\SourceFileReader;
use Firehed\PhpLsp\Knowledge\DeclarationScanner;
use Firehed\PhpLsp\Knowledge\DeclarationSymbolInfoFactory;
use Firehed\PhpLsp\Knowledge\ParsedDeclarationSource;
use Firehed\PhpLsp\Parser\ParseMetrics;
use Firehed\PhpLsp\Parser\SyntaxSource\CompositeSyntaxSource;
use Firehed\PhpLsp\Parser\SyntaxSource\CursorTextSyntaxSource;
use Firehed\PhpLsp\Parser\SyntaxSource\MemoizingSyntaxSource;
use Firehed\PhpLsp\Parser\SyntaxSource\PhpParserSyntaxSource;
use Firehed\PhpLsp\Parser\SyntaxSource\SkeletonSyntaxSource;
use Firehed\PhpLsp\Parser\TreeAnnotator;

/**
 * The test-facing wiring for the production SyntaxSourceInterface stack. Mirrors what
 * {@see \Firehed\PhpLsp\Server::forProject} assembles, so a test that needs
 * production wiring does not name any implementation directly.
 *
 * The factory keeps the {@see ParseMetrics} and the {@see SourceFileReader}
 * reachable because tests observe parse counts and load files by path; the
 * production code inside src/ never reads either of those through the factory.
 */
final readonly class ProductionSyntaxSource
{
    public MemoizingSyntaxSource $source;
    public ParseMetrics $metrics;
    public SourceFileReader $reader;
    public ParsedDeclarationSource $declarations;

    private function __construct()
    {
        $this->metrics = new ParseMetrics();
        $this->source = new MemoizingSyntaxSource(
            new CompositeSyntaxSource(
                new PhpParserSyntaxSource(new TreeAnnotator(), $this->metrics),
                new SkeletonSyntaxSource(),
                new CursorTextSyntaxSource(),
            ),
        );
        $this->reader = new SourceFileReader();
        $this->declarations = new ParsedDeclarationSource(
            $this->source,
            new DeclarationScanner(),
            new DeclarationSymbolInfoFactory(),
        );
    }

    public static function create(): self
    {
        return new self();
    }
}
