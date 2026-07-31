<?php

declare(strict_types=1);

namespace Firehed\PhpLsp\Capability;

use Firehed\PhpLsp\Client\ClientConnection;

/**
 * Registers the server for `workspace/didChangeWatchedFiles` events once the
 * session is initialized, so the client reports on-disk changes the server uses to
 * invalidate cached workspace state (RFC 1 §5.2, §5.3).
 *
 * The feature has no static server capability — [LSP] states the protocol "doesn't
 * support static configuration for file changes from the server side" — so it can
 * only be registered dynamically ([LSP] Register Capability), and only when the
 * client declared it supports that (RFC 1 §4.8). A client that did not is left
 * unregistered; its cached workspace state then follows the §7 fallback (no
 * invalidation until a file is opened and closed).
 */
final class WatchedFilesRegistrar implements InitializedListener
{
    /**
     * A stable id for the registration; the server never unregisters, so it need
     * not be unique per session, only identify this capability ([LSP] Registration).
     */
    private const string REGISTRATION_ID = 'workspace/didChangeWatchedFiles';

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
                    'id' => self::REGISTRATION_ID,
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
