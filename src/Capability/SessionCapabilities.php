<?php

declare(strict_types=1);

namespace Firehed\PhpLsp\Capability;

use Firehed\PhpLsp\Protocol\MarkupKind;
use Firehed\PhpLsp\Protocol\PositionEncoding;

/**
 * The client's declared capabilities, resolved once during `initialize` into an
 * immutable value.
 *
 * Every capability the client did not declare resolves to this value's own
 * default state, so a minimal or older client is served by the default
 * configuration rather than by a branch at the point of use.
 *
 * The value carries only already-resolved capabilities: it exposes no way to
 * build itself from a `Message`, so no component outside `CapabilityNegotiator`
 * can re-read the raw `initialize` parameters through it.
 */
final readonly class SessionCapabilities
{
    public function __construct(
        public MarkupKind $hoverMarkupKind = MarkupKind::PlainText,
        public bool $snippetSupport = false,
        public PositionEncoding $positionEncoding = PositionEncoding::Utf16,
        public bool $watchedFilesDynamicRegistration = false,
    ) {
    }
}
