<?php

declare(strict_types=1);

namespace Firehed\PhpLsp\Capability;

use Firehed\PhpLsp\Protocol\Message;

/**
 * The negotiation package is the one place RFC 1 §4.8 allows the raw
 * `initialize` parameters to be read.
 */
final class NegotiatesRawCapabilities
{
    public function declaresAnything(Message $message): bool
    {
        $params = $message->params ?? [];
        $capabilities = $params['capabilities'] ?? [];

        return $capabilities !== [];
    }
}
