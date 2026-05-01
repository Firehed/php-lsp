<?php

declare(strict_types=1);

namespace Fixtures\Completion;

use Fixtures\Domain\User;

class ChainCompletion
{
    private User $user;

    public function getUser(): User
    {
        return $this->user;
    }

    public function triggerPropertyChain(): void
    {
        $this->user->/*|property_chain*/
    }

    public function triggerMethodChain(): void
    {
        $this->getUser()->/*|method_chain*/
    }

    public function triggerMultiLevelChain(): void
    {
        $this->getUser()->getName()->/*|multi_level_chain*/
    }
}
