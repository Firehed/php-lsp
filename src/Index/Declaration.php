<?php

declare(strict_types=1);

namespace Firehed\PhpLsp\Index;

use Firehed\PhpLsp\Domain\QualifiedName;
use PhpParser\Node;

/**
 * One name a file declares, paired with the node that declares it.
 *
 * The name alone answers what a name -> file map needs. A backend building metadata
 * needs the node too, and re-finding it means a second walk that can disagree with
 * the first about which declarations count.
 *
 * @template-covariant TNode of Node
 */
final readonly class Declaration
{
    /**
     * @param TNode $node
     */
    public function __construct(
        public QualifiedName $name,
        public Node $node,
    ) {
    }
}
