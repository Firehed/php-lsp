<?php

declare(strict_types=1);

namespace Fixtures\Traits;

use DateTimeImmutable;

/**
 * Provides created/updated timestamp tracking.
 */
trait HasTimestamps
{
    /**
     * When the entity was created.
     */
    private ?DateTimeImmutable $createdAt = null;

    /**
     * When the entity was last updated.
     */
    private ?DateTimeImmutable $updatedAt = null;

    /**
     * Display name for the entity.
     */
    protected string $displayName = '';

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

    public function triggerTraitPropertyHover(): void
    {
        echo $this->displayName; //hover:trait_property
    }
}
