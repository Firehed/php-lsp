<?php

declare(strict_types=1);

namespace Firehed\PhpLsp\Tests\Architecture\Data;

use Firehed\PhpLsp\Protocol\Message;

/**
 * A component outside the negotiation package re-inspecting the raw
 * `initialize` parameters, which the rule forbids.
 */
final class ReadsRawCapabilities
{
    public function prefersMarkdown(Message $message): bool
    {
        $params = $message->params ?? [];
        $capabilities = $params['capabilities'] ?? [];

        return $capabilities !== [];
    }
}
