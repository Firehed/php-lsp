<?php

declare(strict_types=1);

namespace Firehed\PhpLsp\Watch;

use Firehed\PhpLsp\Filesystem\PathStamp;

/**
 * What a watched path looked like when it was last looked at.
 */
final readonly class Snapshot
{
    /**
     * @param ?PathStamp $stamp Null when the path did not exist
     * @param int $seenAt Unix time of the look
     * @param list<string> $files For a directory, the PHP files directly inside
     */
    public function __construct(
        public ?PathStamp $stamp,
        public int $seenAt,
        public array $files = [],
    ) {
    }

    /**
     * The filesystem reports whole seconds, so a path last looked at during the
     * second it was modified may have been modified again since: an unchanged
     * stamp proves nothing within its own second.
     */
    public function mayDifferFrom(?PathStamp $current): bool
    {
        if ($this->stamp === null || $current === null) {
            return $this->stamp !== $current;
        }

        return $current->modifiedAt !== $this->stamp->modifiedAt
            || $current->size !== $this->stamp->size
            || $current->modifiedAt >= $this->seenAt;
    }
}
