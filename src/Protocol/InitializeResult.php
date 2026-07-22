<?php

declare(strict_types=1);

namespace Firehed\PhpLsp\Protocol;

use Firehed\PhpLsp\ServerInfo;
use JsonSerializable;

/**
 * The `initialize` response, per [LSP] "Server lifecycle" (`InitializeResult`).
 *
 * @phpstan-type ServerCapabilities array{
 *   positionEncoding: string,
 *   textDocumentSync: array{
 *     openClose: bool,
 *     change: int,
 *     save: bool,
 *   },
 *   definitionProvider: bool,
 *   hoverProvider: bool,
 *   signatureHelpProvider: array{
 *     triggerCharacters: list<string>,
 *   },
 *   completionProvider: array{
 *     triggerCharacters: list<string>,
 *   },
 * }
 */
final readonly class InitializeResult implements JsonSerializable
{
    /**
     * @param ServerCapabilities $capabilities
     */
    public function __construct(
        public array $capabilities,
        public ServerInfo $serverInfo,
    ) {
    }

    /**
     * @return array{
     *   capabilities: ServerCapabilities,
     *   serverInfo: array{name: string, version: string},
     * }
     */
    public function jsonSerialize(): array
    {
        return [
            'capabilities' => $this->capabilities,
            'serverInfo' => $this->serverInfo->toArray(),
        ];
    }
}
