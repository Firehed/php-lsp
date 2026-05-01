<?php

declare(strict_types=1);

namespace Fixtures\Completion;

use Fixtures\Domain\User;

class ChainCompletion
{
    private User $user;
    private ?User $nullableUser;
    private ?Address $nullableAddress;
    private ChainableUser $chainableUser;

    public function getUser(): User
    {
        return $this->user;
    }

    public function getChainableUser(): ChainableUser
    {
        return $this->chainableUser;
    }

    public function triggerPropertyChain(): void
    {
        $this->user->/*|property_chain*/
    }

    public function triggerMethodChain(): void
    {
        $this->getUser()->/*|method_chain*/
    }

    public function triggerPrimitiveChain(): void
    {
        $this->getUser()->getName()->/*|primitive_chain*/
    }

    public function triggerMultiLevelChain(): void
    {
        $this->getChainableUser()->getName()->/*|multi_level_chain*/
    }

    public function triggerNullsafePropertyChain(): void
    {
        $this->nullableUser?->/*|nullsafe_property_chain*/
    }

    public function triggerStaticMethodChain(): void
    {
        $builder = Builder::create();
        $builder->/*|static_method_chain*/
    }

    public function triggerMultiLineChain(): void
    {
        $this->getChainableUser()
            ->getName()
            ->/*|multi_line_chain*/
    }

    public function triggerMixedNullsafeChain(): void
    {
        $this->user->nullableAddress?->/*|mixed_nullsafe_chain*/
    }
}
