<?php

declare(strict_types=1);

namespace Firehed\PhpLsp\Tests\EndToEnd;

use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\TestCase;
use stdClass;

#[CoversNothing]
final class LifecycleTest extends TestCase
{
    private const string PROJECT = __DIR__ . '/../Fixtures';

    public function testCleanSessionExitsZero(): void
    {
        $server = ServerProcess::start(self::PROJECT);
        $client = new LspClient($server);

        $initialize = $client->request('initialize', (object) [
            'processId' => null,
            'rootUri' => 'file://' . self::PROJECT,
            'capabilities' => new stdClass(),
        ]);
        $client->notify('initialized', new stdClass());
        $shutdown = $client->request('shutdown');
        $client->notify('exit');

        self::assertSame(0, $server->waitForExit());
        self::assertSame('', $server->stderr);

        self::assertNull($initialize->error);
        self::assertInstanceOf(stdClass::class, $initialize->result);
        self::assertObjectHasProperty('capabilities', $initialize->result);
        self::assertObjectHasProperty('serverInfo', $initialize->result);

        self::assertNull($shutdown->error);
        self::assertNull($shutdown->result);
    }

    public function testSilentServerFailsTheReadAtItsDeadline(): void
    {
        $server = ServerProcess::start(self::PROJECT);

        $this->expectException(FrameNotReceived::class);
        $server->readFrame(deadline: 0.1);
    }
}
