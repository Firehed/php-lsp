<?php

declare(strict_types=1);

namespace Firehed\PhpLsp\Tests\Architecture\Data;

use Firehed\PhpLsp\Protocol\InitializeResult;

/**
 * Reading the server's *own* advertised capabilities off a typed
 * `InitializeResult` is not a read of the raw `initialize` parameters, so RFC 1
 * §4.8 does not forbid it. The key name is the same; the provenance is not.
 */
final class ReadsOwnResultCapabilities
{
    public function advertisesHover(InitializeResult $result): bool
    {
        $serialized = $result->jsonSerialize();

        return $serialized['capabilities']['hoverProvider'];
    }
}
