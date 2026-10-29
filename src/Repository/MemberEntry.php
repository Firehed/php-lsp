<?php

declare(strict_types=1);

namespace Firehed\PhpLsp\Repository;

use Firehed\PhpLsp\Domain\ClassInfo;
use Firehed\PhpLsp\Domain\ClasslikeName;
use Firehed\PhpLsp\Domain\MemberInfoInterface;

/**
 * One name in a class-like's member table: the member, the class-like whose
 * table holds it as its own (the class using a trait, for a member the trait
 * brings in), and the entry it overrides as its prototype.
 */
final readonly class MemberEntry
{
    public function __construct(
        public MemberInfoInterface $member,
        public ClassInfo $declaring,
        private ?MemberEntry $prototype = null,
    ) {
    }

    /**
     * The entry at the root of this one's overrides: its prototype, else
     * itself. A member overriding this one takes it as its own prototype.
     */
    public function rootEntry(): self
    {
        return $this->prototype ?? $this;
    }

    /**
     * The class a protected member is checked against
     * (`zend_get_function_root_class` in php-src).
     */
    public function root(): ClasslikeName
    {
        return $this->rootEntry()->declaring->name;
    }

    public function withPrototype(self $prototype): self
    {
        return new self($this->member, $this->declaring, $prototype);
    }
}
