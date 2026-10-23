<?php

declare(strict_types=1);

namespace Firehed\PhpLsp\Domain;

/**
 * What `static`, `self`, and `parent` name where a member is used: the class
 * it is called on, the class declaring it, and that class's parent.
 */
final readonly class LateBinding
{
    public function __construct(
        public ClasslikeName $static,
        public ClasslikeName $self,
        public ?ClasslikeName $parent,
    ) {
    }

    public function classFor(LateBindingKeyword $keyword): ?ClasslikeName
    {
        return match ($keyword) {
            LateBindingKeyword::Static => $this->static,
            LateBindingKeyword::Self => $this->self,
            LateBindingKeyword::Parent => $this->parent,
        };
    }
}
