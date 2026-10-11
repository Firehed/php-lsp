<?php

declare(strict_types=1);

namespace Firehed\PhpLsp\Capability;

use Firehed\PhpLsp\Protocol\Message;

/**
 * The negotiation package is the one place allowed to read the raw
 * `initialize` parameters.
 */
final class NegotiatesRawCapabilities
{
    public function declaresAnything(Message $message): bool
    {
        $params = $message->params ?? [];
        $capabilities = $params['capabilities'] ?? [];

        return $capabilities !== [];
    }

    public function offersAnEncoding(Message $message): bool
    {
        $params = $message->params ?? [];
        $general = $params['general'] ?? [];

        return $general !== [];
    }
}
