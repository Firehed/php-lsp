<?php

declare(strict_types=1);

namespace Firehed\PhpLsp\Tests\Parser;

use Firehed\PhpLsp\Document\SourceFileReader;
use Firehed\PhpLsp\Knowledge\DeclarationScanner;
use Firehed\PhpLsp\Knowledge\DeclarationSymbolInfoFactory;
use Firehed\PhpLsp\Knowledge\ParsedDeclarationSource;
use Firehed\PhpLsp\Parser\SyntaxSource\CompositeNodeLocator;
use Firehed\PhpLsp\Parser\SyntaxSource\CompositeSyntaxSource;
use Firehed\PhpLsp\Parser\SyntaxSource\CursorTextNodeLocator;
use Firehed\PhpLsp\Parser\SyntaxSource\MemoizingSyntaxSource;
use Firehed\PhpLsp\Parser\SyntaxSource\NodeLocatorInterface;
use Firehed\PhpLsp\Parser\SyntaxSource\PhpParserSyntaxSource;
use Firehed\PhpLsp\Parser\SyntaxSource\SkeletonSyntaxSource;
use Firehed\PhpLsp\Parser\SyntaxSource\TreeNodeLocator;
use Firehed\PhpLsp\Parser\TreeAnnotator;

/**
 * The test-facing wiring for the production SyntaxSourceInterface and
 * NodeLocatorInterface stacks. Mirrors what
 * {@see \Firehed\PhpLsp\Server::forProject} assembles, so a test that needs
 * production wiring does not name any implementation directly.
 *
 * The exposed {@see CountingSyntaxSource} sits under the memoizer, so tests can
 * assert how many times the real parser actually ran rather than how many times
 * the memoizer was consulted.
 */
final readonly class ProductionSyntaxSource
{
    public MemoizingSyntaxSource $source;
    public NodeLocatorInterface $locator;
    public CountingSyntaxSource $counter;
    public SourceFileReader $reader;
    public ParsedDeclarationSource $declarations;

    private function __construct()
    {
        $this->counter = new CountingSyntaxSource(
            new CompositeSyntaxSource(
                new PhpParserSyntaxSource(new TreeAnnotator()),
                new SkeletonSyntaxSource(),
            ),
        );
        $this->source = new MemoizingSyntaxSource($this->counter);
        $this->locator = new CompositeNodeLocator(new TreeNodeLocator(), new CursorTextNodeLocator());
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
