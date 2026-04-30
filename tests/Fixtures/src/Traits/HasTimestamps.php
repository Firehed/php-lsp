<?php

declare(strict_types=1);

namespace Fixtures\Traits;

use DateTimeImmutable;

/**
 * Provides created/updated timestamp tracking.
 */
trait HasTimestamps
{
    private ?DateTimeImmutable $createdAt = null;
    private ?DateTimeImmutable $updatedAt = null;

    public function getCreatedAt(): ?DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function getUpdatedAt(): ?DateTimeImmutable
    {
        return $this->updatedAt;
    }

    public function markCreated(): void
    {
        $this->createdAt = new DateTimeImmutable();
    }

    public function markUpdated(): void
    {
        $this->updatedAt = new DateTimeImmutable();
    }
}
