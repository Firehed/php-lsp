<?php

declare(strict_types=1);

namespace Firehed\PhpLsp\Capability;

use Firehed\PhpLsp\Protocol\MarkupKind;
use Firehed\PhpLsp\Protocol\Message;

/**
 * The client's declared capabilities, resolved once during `initialize` into an
 * immutable value (RFC 1 §4.8, §5.4).
 *
 * Every capability the client did not declare resolves to this value's own
 * default state, so a minimal or older client is served by the default
 * configuration rather than by a branch at the point of use.
 *
 * This is the only place the raw `initialize` parameters are read.
 */
final readonly class SessionCapabilities
{
    public function __construct(
        public MarkupKind $hoverMarkupKind = MarkupKind::PlainText,
        public bool $snippetSupport = false,
    ) {
    }

    public static function fromMessage(Message $message): self
    {
        $capabilities = self::readMap($message->params ?? [], 'capabilities');
        $textDocument = self::readMap($capabilities, 'textDocument');
        $completionItem = self::readMap(self::readMap($textDocument, 'completion'), 'completionItem');

        return new self(
            hoverMarkupKind: self::negotiateHoverMarkupKind(self::readMap($textDocument, 'hover')),
            snippetSupport: ($completionItem['snippetSupport'] ?? false) === true,
        );
    }

    /**
     * Per [LSP] `HoverClientCapabilities`, `contentFormat` lists the kinds the
     * client supports in the order it prefers them; the first the server also
     * supports wins.
     *
     * @param array<array-key, mixed> $hover
     */
    private static function negotiateHoverMarkupKind(array $hover): MarkupKind
    {
        foreach (self::readMap($hover, 'contentFormat') as $format) {
            $kind = is_string($format) ? MarkupKind::tryFrom($format) : null;
            if ($kind !== null) {
                return $kind;
            }
        }

        return MarkupKind::PlainText;
    }

    /**
     * @param array<array-key, mixed> $source
     *
     * @return array<array-key, mixed>
     */
    private static function readMap(array $source, string $key): array
    {
        $value = $source[$key] ?? null;

        return is_array($value) ? $value : [];
    }
}
