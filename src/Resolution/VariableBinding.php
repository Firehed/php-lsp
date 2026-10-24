<?php

declare(strict_types=1);

namespace Firehed\PhpLsp\Resolution;

use PhpParser\Node;

/**
 * A single variable binding: a parameter, an assignment target, a foreach
 * key/value, a catch variable, a long-closure `use` clause, or `$this`. The
 * node is the exact one to point at for go-to-definition, and null for `$this`,
 * which PHP binds without code; the scope is the one it binds in.
 */
final readonly class VariableBinding
{
    public function __construct(
        public string $name,
        public ?Node $node,
        public Scope $scope,
    ) {
    }
}
