<?php

declare(strict_types=1);

namespace Firehed\PhpLsp\Domain;

/**
 * What `static`, `self`, and `parent` name: for a member, the class it is
 * called on, the class declaring it, and that class's parent; for code, the
 * class it is written in twice, and that class's parent. Each is null where
 * there is no such class; an anonymous class has a parent but no name.
 */
final readonly class LateBinding
{
    public function __construct(
        public ?ClasslikeName $static,
        public ?ClasslikeName $self,
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
