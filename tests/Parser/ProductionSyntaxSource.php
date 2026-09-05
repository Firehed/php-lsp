<?php

declare(strict_types=1);

namespace Firehed\PhpLsp\Tests\Parser;

use Firehed\PhpLsp\Parser\ParseMetrics;
use Firehed\PhpLsp\Parser\SourceFileReader;
use Firehed\PhpLsp\Parser\SyntaxSource\CompositeSyntaxSource;
use Firehed\PhpLsp\Parser\SyntaxSource\MemoizingSyntaxSource;
use Firehed\PhpLsp\Parser\SyntaxSource\PhpParserSyntaxSource;
use Firehed\PhpLsp\Parser\SyntaxSource\SkeletonSyntaxSource;
use Firehed\PhpLsp\Parser\TreeAnnotator;

/**
 * The test-facing wiring for the production SyntaxSource stack. Mirrors what
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

    private function __construct()
    {
        $this->metrics = new ParseMetrics();
        $this->source = new MemoizingSyntaxSource(
            new CompositeSyntaxSource([
                new PhpParserSyntaxSource(new TreeAnnotator(), $this->metrics),
                new SkeletonSyntaxSource(),
            ]),
        );
        $this->reader = new SourceFileReader();
    }

    public static function create(): self
    {
        return new self();
    }
}
