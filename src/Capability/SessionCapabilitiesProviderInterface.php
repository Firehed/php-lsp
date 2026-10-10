<?php

declare(strict_types=1);

namespace Firehed\PhpLsp\Capability;

use Firehed\PhpLsp\Protocol\InitializeResult;
use Firehed\PhpLsp\Protocol\Message;

/**
 * Read-only access to the resolved {@see SessionCapabilities} for the components
 * that shape output by client support (RFC 1 §4.8, §5.4).
 *
 * Output-shaping components are constructed before `initialize` runs, so they
 * cannot receive the resolved value directly; they depend on this interface and
 * read the current value when they handle a message. Exposing only the read keeps
 * the raw `initialize` parameters confined to {@see CapabilityNegotiator} — a
 * consumer cannot reach `negotiate()` or the parameters through it.
 */
interface SessionCapabilitiesProviderInterface
{
    public function getSessionCapabilities(): SessionCapabilities;

    // Note: to simplify the initial wiring, this was added into the existing
    // interface since there's a single implementation. It's a bit of a code
    // smell, and should eventually split out.
    public function negotiate(Message $message): InitializeResult;
}
