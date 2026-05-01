<?php

declare(strict_types=1);

namespace Fixtures\Domain;

use Fixtures\Enum\Status;
use Fixtures\Traits\HasTimestamps;

/**
 * Represents a system user.
 *
 * Users can authenticate and perform actions within the application.
 */
class User implements Entity, Person
{
    use HasTimestamps;

    public const DEFAULT_ROLE = 'user';

    private static int $instanceCount = 0;

    public function __construct(
        private readonly string $id,
        private string $name,
        private string $email,
        private int $age = 0,
        private Status $status = Status::Active,
        public ?self $manager = null,
    ) {
        self::$instanceCount++;
    }

    public function getId(): string
    {
        return $this->id;
    }

    public function getName(): string
    {
        return $this->name;
    }

    /**
     * Updates the user's display name.
     */
    public function setName(string $name): void
    {
        $this->name = $name;
    }

    public function getEmail(): string
    {
        return $this->email;
    }

    public function getAge(): int
    {
        return $this->age;
    }

    public function getStatus(): Status
    {
        return $this->status;
    }

    public function activate(): void
    {
        $this->status = Status::Active;
    }

    public function deactivate(): void
    {
        $this->status = Status::Inactive;
    }

    public function isActive(): bool
    {
        return $this->status === Status::Active;
    }

    public static function getInstanceCount(): int
    {
        return self::$instanceCount;
    }

    public static function create(string $id, string $name, string $email): self
    {
        return new self($id, $name, $email);
    }

    public function triggerSigThis(): void
    {
        $this->setName(/*|sig_this_call*/"name");
    }

    public function triggerSigSelf(): self
    {
        return self::create(/*|sig_self_call*/"id", "name", "email");
    }

    public function triggerSigNullsafe(): void
    {
        $this->manager?->setName(/*|sig_nullsafe_property*/"name");
    }
}
