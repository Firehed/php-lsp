<?php

declare(strict_types=1);

namespace Firehed\PhpLsp\Parser\SyntaxSource;

use Firehed\PhpLsp\Parser\ParsedDocument;
use PhpParser\Node;

/**
 * The one-route composite for {@see NodeLocatorInterface}. The parsed tree is
 * asked first; the cursor text answers only where the tree holds nothing at
 * the offset.
 */
final class CompositeNodeLocator implements NodeLocatorInterface
{
    /** @var list<NodeLocatorInterface> */
    private readonly array $locators;

    public function __construct(
        TreeNodeLocator $tree,
        CursorTextSyntaxSource $cursorText,
    ) {
        $this->locators = [$tree, $cursorText];
    }

    public function nodeAt(ParsedDocument $parsed, int $offset): ?Node
    {
        foreach ($this->locators as $locator) {
            $node = $locator->nodeAt($parsed, $offset);
            if ($node !== null) {
                return $node;
            }
        }
        return null;
    }
}
