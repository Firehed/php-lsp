<?php

declare(strict_types=1);

namespace Firehed\PhpLsp\Tests\EndToEnd;

/**
 * The client's file watcher reporting what changed on disk, in one
 * notification.
 */
final readonly class ReportChanges implements StepInterface
{
    /**
     * @param list<FileChange> $changes
     */
    public function __construct(private array $changes)
    {
    }

    public function run(Session $session): void
    {
        $session->reportChanges($this->changes);
    }
}
