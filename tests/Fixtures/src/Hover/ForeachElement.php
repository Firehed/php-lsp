<?php

declare(strict_types=1);

namespace Fixtures\Hover;

use Fixtures\Domain\User;

class UserCollection
{
    /**
     * @return User[]
     */
    public function all(): array
    {
        return [];
    }
}

class ForeachElement
{
    public function iterateUsers(UserCollection $users): void
    {
        foreach ($users->all() as $user) {
            $user->getName(); //hover:foreach_member
        }
    }
}
