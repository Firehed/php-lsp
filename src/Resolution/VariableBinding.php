<?php

declare(strict_types=1);

namespace Firehed\PhpLsp\Resolution;

use Firehed\PhpLsp\Domain\TypeInterface;
use PhpParser\Node;

/**
 * A single variable binding: a parameter, an assignment target, a foreach
 * key/value, a catch variable, a long-closure `use` clause, or `$this`. A
 * binding code writes has the node to point at for go-to-definition, and its
 * code gives its type; one PHP makes without code has no node, and carries the
 * type PHP gives it. The scope is the one it binds in.
 */
final readonly class VariableBinding
{
    private function __construct(
        public string $name,
        public ?Node $node,
        public Scope $scope,
        public ?TypeInterface $type,
    ) {
    }

    public static function written(string $name, Node $node, Scope $scope): self
    {
        return new self($name, $node, $scope, null);
    }

    public static function implicit(string $name, TypeInterface $type, Scope $scope): self
    {
        return new self($name, null, $scope, $type);
    }
}
