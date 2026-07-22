<?php

declare(strict_types=1);

namespace Firehed\PhpLsp\Tests\Architecture\Data;

use Firehed\PhpLsp\Protocol\Message;

/**
 * A component outside the negotiation package re-inspecting the raw
 * `initialize` `general` parameters (position encoding), which RFC 1 §4.9
 * confines to the negotiation component.
 */
final class ReadsRawGeneral
{
    public function prefersUtf8(Message $message): bool
    {
        $params = $message->params ?? [];
        $general = $params['general'] ?? [];

        return $general !== [];
    }
}
