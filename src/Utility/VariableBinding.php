<?php

declare(strict_types=1);

namespace Firehed\PhpLsp\Utility;

use PhpParser\Node;

/**
 * A single variable binding site: a parameter, an assignment target, a foreach
 * key/value, a catch variable, or a long-closure `use` clause. The node is the
 * exact one to point at for go-to-definition.
 */
final readonly class VariableBinding
{
    public function __construct(
        public string $name,
        public Node $node,
    ) {
    }
}
