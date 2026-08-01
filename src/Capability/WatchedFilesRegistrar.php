<?php

declare(strict_types=1);

namespace Firehed\PhpLsp\Capability;

use Firehed\PhpLsp\Client\ClientConnection;

/**
 * Registers the server for `workspace/didChangeWatchedFiles` events once the
 * session is initialized, so the client reports on-disk changes used to invalidate
 * cached workspace state (RFC 1 §5.2, §5.3). The feature has no static server
 * capability, so it can only be registered dynamically ([LSP] Register Capability),
 * and only when the client declared support (RFC 1 §4.8); a client that did not is
 * left on the §7 fallback (no invalidation until a file is opened and closed).
 */
final class WatchedFilesRegistrar implements InitializedListener
{
    // The registration id doubles as the method name: the server never unregisters,
    // so it only has to identify the capability ([LSP] Registration).
    private const string METHOD = 'workspace/didChangeWatchedFiles';

    public function __construct(
        private readonly ClientConnection $client,
    ) {
    }

    public function onInitialized(SessionCapabilities $capabilities): void
    {
        if (!$capabilities->watchedFilesDynamicRegistration) {
            return;
        }

        $this->client->request('client/registerCapability', [
            'registrations' => [
                [
                    'id' => self::METHOD,
                    'method' => self::METHOD,
                    'registerOptions' => [
                        'watchers' => [
                            ['globPattern' => '**/*.php'],
                        ],
                    ],
                ],
            ],
        ]);
    }
}
