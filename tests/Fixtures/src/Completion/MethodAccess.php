<?php

declare(strict_types=1);

namespace Fixtures\Completion;

use Fixtures\Domain\User;

class MethodAccess
{
    private string $name;
    protected int $count;
    public bool $active;

    public function getName(): string
    {
        return $this->name;
    }

    public function setName(string $name): void
    {
        $this->name = $name;
    }

    public function getCount(): int
    {
        return $this->count;
    }

    public function isActive(): bool
    {
        return $this->active;
    }

    public function triggerThisEmpty(): void
    {
        $this->/*|this_empty*/
    }

    public function triggerThisPrefix(): void
    {
        $this->get/*|this_prefix*/
    }

    public function triggerVarEmpty(): void
    {
        $obj = new self();
        $obj->/*|var_empty*/
    }

    public function triggerVarPrefix(): void
    {
        $obj = new self();
        $obj->get/*|var_prefix*/
    }

    public function withParameter(self $param): void
    {
        $param->/*|param_access*/
    }

    public function chainExample(): void
    {
        $result = $this->getName()->/*|chain_on_string*/
    }

    public function triggerNullsafeThis(): void
    {
        $this?->/*|nullsafe_this_empty*/
    }

    public function triggerNullsafeThisPrefix(): void
    {
        $this?->get/*|nullsafe_this_prefix*/
    }

    public function triggerNullsafeVar(?User $user): void
    {
        $user?->/*|nullsafe_var_empty*/
    }
}
