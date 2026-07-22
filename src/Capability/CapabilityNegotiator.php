<?php

declare(strict_types=1);

namespace Firehed\PhpLsp\Capability;

use Firehed\PhpLsp\Protocol\InitializeResult;
use Firehed\PhpLsp\Protocol\MarkupKind;
use Firehed\PhpLsp\Protocol\Message;
use Firehed\PhpLsp\ServerInfo;

/**
 * The protocol negotiation component (RFC 1 §4.8): the one place that reads the
 * raw `initialize` parameters, and the source of the `SessionCapabilities` that
 * every output-shaping decision queries instead.
 *
 * @phpstan-import-type ServerCapabilities from InitializeResult
 */
final class CapabilityNegotiator
{
    private SessionCapabilities $sessionCapabilities;

    public function __construct(
        private readonly ServerInfo $serverInfo,
    ) {
        $this->sessionCapabilities = new SessionCapabilities();
    }

    public function getSessionCapabilities(): SessionCapabilities
    {
        return $this->sessionCapabilities;
    }

    public function negotiate(Message $message): InitializeResult
    {
        $this->sessionCapabilities = $this->resolveSessionCapabilities($message);

        return new InitializeResult(
            capabilities: $this->advertisedCapabilities(),
            serverInfo: $this->serverInfo,
        );
    }

    private function resolveSessionCapabilities(Message $message): SessionCapabilities
    {
        $capabilities = self::readMap($message->params ?? [], 'capabilities');
        $textDocument = self::readMap($capabilities, 'textDocument');
        $completionItem = self::readMap(self::readMap($textDocument, 'completion'), 'completionItem');

        return new SessionCapabilities(
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

    /**
     * Only capabilities the server implements are advertised. A client that did
     * not declare support for one simply will not invoke it; per RFC 1 §4.8 the
     * shaping of each response is decided by `SessionCapabilities`, not by this
     * list.
     *
     * @return ServerCapabilities
     */
    private function advertisedCapabilities(): array
    {
        return [
            'textDocumentSync' => [
                'openClose' => true,
                'change' => 1, // TextDocumentSyncKind.Full
                'save' => false,
            ],
            'definitionProvider' => true,
            'hoverProvider' => true,
            'signatureHelpProvider' => [
                'triggerCharacters' => ['(', ','],
            ],
            'completionProvider' => [
                // Note: ':' omitted - fires prematurely on first ':' of '::'
                'triggerCharacters' => ['>', '$', '\\'],
            ],
        ];
    }
}
