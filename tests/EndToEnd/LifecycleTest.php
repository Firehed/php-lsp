<?php

declare(strict_types=1);

namespace Firehed\PhpLsp\Tests\EndToEnd;

use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\TestCase;
use stdClass;

#[CoversNothing]
final class LifecycleTest extends TestCase
{
    use StartsServerTrait;

    private const string PROJECT = __DIR__ . '/../Fixtures';

    public function testCleanSessionExitsZero(): void
    {
        $server = $this->startServer(self::PROJECT);
        $client = new LspClient($server);

        $initialize = $client->request('initialize', (object) [
            'processId' => null,
            'rootUri' => 'file://' . self::PROJECT,
            'capabilities' => new stdClass(),
        ]);
        $client->notify('initialized', new stdClass());
        $shutdown = $client->request('shutdown');
        $client->notify('exit');

        self::assertSame(0, $server->waitForExit(), 'exit after shutdown is a clean exit ([LSP] exit)');
        self::assertSame('', $server->stderr(), 'the server writes nothing to stderr in a clean session');

        self::assertNull($initialize->error, 'initialize succeeds');
        self::assertInstanceOf(stdClass::class, $initialize->result, 'InitializeResult is an object');
        self::assertObjectHasProperty('capabilities', $initialize->result, 'InitializeResult requires capabilities');
        self::assertObjectHasProperty('serverInfo', $initialize->result, 'the server identifies itself');

        self::assertNull($shutdown->error, 'shutdown succeeds');
        self::assertNull($shutdown->result, 'the shutdown result is null ([LSP] shutdown)');
    }

    public function testSilentServerFailsTheReadAtItsDeadline(): void
    {
        $server = $this->startServer(self::PROJECT);

        $this->expectException(FrameNotReceivedException::class);
        $server->readMessage(deadline: 0.1);
    }
}
