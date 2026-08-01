<?php

declare(strict_types=1);

namespace Firehed\PhpLsp\Tests\Client;

use Firehed\PhpLsp\Client\TransportClientConnection;
use Firehed\PhpLsp\Protocol\OutgoingMessage;
use Firehed\PhpLsp\Transport\TransportInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(TransportClientConnection::class)]
class TransportClientConnectionTest extends TestCase
{
    public function testRequestWritesAServerInitiatedRequestToTheTransport(): void
    {
        $written = [];
        $connection = new TransportClientConnection($this->capturingTransport($written));

        $connection->request('client/registerCapability', ['registrations' => ['x']]);

        self::assertSame(
            [[
                'jsonrpc' => '2.0',
                'id' => 'server-1',
                'method' => 'client/registerCapability',
                'params' => ['registrations' => ['x']],
            ]],
            $written,
            'the method and params must be forwarded verbatim as a well-formed server request',
        );
    }

    public function testEachRequestGetsADistinctNamespacedId(): void
    {
        $written = [];
        $connection = new TransportClientConnection($this->capturingTransport($written));

        $connection->request('a', []);
        $connection->request('b', []);

        self::assertSame(
            ['server-1', 'server-2'],
            array_column($written, 'id'),
            'ids must be unique and namespaced so they never collide with the client\'s own ids',
        );
    }

    /**
     * @param list<array<array-key, mixed>> $written captured serialized frames
     */
    private function capturingTransport(array &$written): TransportInterface
    {
        $transport = self::createStub(TransportInterface::class);
        $transport->method('write')->willReturnCallback(
            function (OutgoingMessage $message) use (&$written): void {
                $written[] = $message->jsonSerialize();
            },
        );

        return $transport;
    }
}
