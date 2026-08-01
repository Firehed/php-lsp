<?php

declare(strict_types=1);

namespace Firehed\PhpLsp\Tests\Protocol;

use Firehed\PhpLsp\Protocol\OutgoingRequest;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(OutgoingRequest::class)]
class OutgoingRequestTest extends TestCase
{
    public function testSerializesAsAJsonRpcRequest(): void
    {
        $request = new OutgoingRequest('server-1', 'client/registerCapability', ['registrations' => []]);

        self::assertSame(
            [
                'jsonrpc' => '2.0',
                'id' => 'server-1',
                'method' => 'client/registerCapability',
                'params' => ['registrations' => []],
            ],
            $request->jsonSerialize(),
            'a server-initiated request carries jsonrpc, id, method, and params',
        );
    }

    public function testAcceptsAnIntegerId(): void
    {
        $request = new OutgoingRequest(7, 'workspace/configuration', []);

        self::assertSame(7, $request->jsonSerialize()['id'], 'an integer id round-trips as an integer');
    }
}
