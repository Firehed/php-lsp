<?php

declare(strict_types=1);

namespace Firehed\PhpLsp\Events;

/**
 * A buffer that had authority over the file at this URI was closed. Disk answers
 * for the URI are once again the truth, and cached on-disk entries for it must
 * be dropped so the next query reflects disk rather than a value cached before
 * the buffer opened (RFC 1 §5.3).
 */
final readonly class OpenDocumentClosedEvent implements FileEventInterface
{
    public string $type;

    public function __construct(public string $uri)
    {
        $this->type = self::class;
    }
}
