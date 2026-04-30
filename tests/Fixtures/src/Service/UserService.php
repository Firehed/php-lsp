<?php

declare(strict_types=1);

namespace Fixtures\Service;

use Fixtures\Domain\User;
use Fixtures\Enum\Status;
use Fixtures\Repository\UserRepository;

/**
 * Service for user operations.
 */
class UserService
{
    public function __construct(
        private readonly UserRepository $repository,
    ) {
    }

    public function findUser(string $id): ?User
    {
        return $this->repository->find($id);
    }

    public function createUser(string $id, string $name, string $email): User
    {
        $user = new User($id, $name, $email);
        $this->repository->save($user);
        return $user;
    }

    public function activateUser(User $user): void
    {
        $user->activate();
        $this->repository->save($user);
    }

    public function deactivateUser(User $user): void
    {
        $user->deactivate();
        $this->repository->save($user);
    }

    /**
     * @return list<User>
     */
    public function getActiveUsers(): array
    {
        return array_filter(
            $this->repository->findAll(),
            static fn(User $user): bool => $user->isActive(),
        );
    }
}
