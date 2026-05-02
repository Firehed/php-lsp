<?php

declare(strict_types=1);

namespace Fixtures\Domain;

/**
 * Represents a team of users.
 */
class Team
{
    public function __construct(
        private readonly string $name,
        private User $leader,
    ) {
    }

    /**
     * Gets the team name.
     */
    public function getName(): string
    {
        return $this->name;
    }

    /**
     * Gets the team leader.
     */
    public function getLeader(): User
    {
        return $this->leader;
    }

    /**
     * Sets the team leader fluently.
     */
    public function withLeader(User $leader): self
    {
        $this->leader = $leader;
        return $this;
    }
}
