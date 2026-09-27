<?php

declare(strict_types=1);

namespace Firehed\PhpLsp\Knowledge;

use Firehed\PhpLsp\Domain\ComposerAutoloadMap;
use Firehed\PhpLsp\Events\EventInterface;

/**
 * Published by the autoload map reader when a file under `vendor/composer/`
 * changed and the re-read map is a new instance. Carries the fresh map so
 * subscribers do not have to consult the reader again — they receive the value
 * they need to rebuild their derived state from directly (RFC 1 §5.2, §5.3).
 *
 * Lives in `Knowledge` because it carries a {@see ComposerAutoloadMap}, the
 * one concrete symbol-discovery collaborator confined to backends by RFC 1 §4.2.
 */
final readonly class AutoloadMapRegeneratedEvent implements EventInterface
{
    public string $type;

    public function __construct(public ComposerAutoloadMap $map)
    {
        $this->type = self::class;
    }
}
