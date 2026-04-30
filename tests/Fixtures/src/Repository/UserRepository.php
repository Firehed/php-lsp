<?php

declare(strict_types=1);

namespace Fixtures\Repository;

use Fixtures\Domain\User;

/**
 * Repository for user persistence.
 */
interface UserRepository
{
    public function find(string $id): ?User;

    public function findByEmail(string $email): ?User;

    /**
     * @return list<User>
     */
    public function findAll(): array;

    public function save(User $user): void;

    public function delete(User $user): void;
}
