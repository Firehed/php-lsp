<?php

declare(strict_types=1);

namespace Firehed\PhpLsp\Tests\Capability;

use Firehed\PhpLsp\Capability\SessionCapabilities;
use Firehed\PhpLsp\Capability\WatchedFilesRegistrar;
use Firehed\PhpLsp\Client\ClientConnection;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(WatchedFilesRegistrar::class)]
class WatchedFilesRegistrarTest extends TestCase
{
    public function testRegistersForWatchedFilesWhenTheClientSupportsDynamicRegistration(): void
    {
        $client = $this->createMock(ClientConnection::class);
        $client->expects($this->once())
            ->method('request')
            ->with(
                'client/registerCapability',
                [
                    'registrations' => [
                        [
                            'id' => 'workspace/didChangeWatchedFiles',
                            'method' => 'workspace/didChangeWatchedFiles',
                            'registerOptions' => [
                                'watchers' => [
                                    ['globPattern' => '**/*.php'],
                                ],
                            ],
                        ],
                    ],
                ],
            );

        $registrar = new WatchedFilesRegistrar($client);
        $registrar->onInitialized(new SessionCapabilities(watchedFilesDynamicRegistration: true));
    }

    public function testDoesNotRegisterWhenTheClientLacksDynamicRegistrationSupport(): void
    {
        $client = $this->createMock(ClientConnection::class);
        // Without client support the feature cannot be registered ([LSP] Register
        // Capability); the §7 fallback (no invalidation) applies instead.
        $client->expects($this->never())->method('request');

        $registrar = new WatchedFilesRegistrar($client);
        $registrar->onInitialized(new SessionCapabilities());
    }
}
