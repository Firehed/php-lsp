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

    private const string PROJECT = 'tests/Fixtures';

    public function testCleanSessionExitsZero(): void
    {
        $projectRoot = $this->projectRoot(self::PROJECT);
        $server = $this->startServer($projectRoot);
        $session = new Session(new LspClient($server), $projectRoot);

        $initialize = $session->start(new ClientCapabilities());
        $shutdown = $session->end();

        self::assertSame(0, $server->waitForExit(), 'exit after shutdown is a clean exit ([LSP] exit)');
        self::assertSame('', $server->stderr(), 'the server writes nothing to stderr in a clean session');

        self::assertNull($initialize->error, 'initialize succeeds');
        self::assertInstanceOf(stdClass::class, $initialize->result, 'InitializeResult is an object');
        self::assertObjectHasProperty('capabilities', $initialize->result, 'InitializeResult requires capabilities');
        self::assertObjectHasProperty('serverInfo', $initialize->result, 'the server identifies itself');

        self::assertNull($shutdown->error, 'shutdown succeeds');
        self::assertNull($shutdown->result, 'the shutdown result is null ([LSP] shutdown)');
    }

    /**
     * The specification does not cover a client that disconnects without
     * `exit`. The server treats it as an exit without shutdown.
     */
    public function testClosedInputExitsOne(): void
    {
        $projectRoot = $this->projectRoot(self::PROJECT);
        $server = $this->startServer($projectRoot);
        (new Session(new LspClient($server), $projectRoot))->start(new ClientCapabilities());

        $server->closeInput();

        self::assertSame(1, $server->waitForExit(), 'a disconnect is not a clean exit');
        self::assertSame('', $server->stderr(), 'a disconnect is not a failure to report');
    }

    public function testExitWithoutShutdownExitsOne(): void
    {
        $server = $this->startServer($this->projectRoot(self::PROJECT));

        (new LspClient($server))->notify('exit');

        self::assertSame(1, $server->waitForExit(), 'exit before shutdown is an error exit ([LSP] exit)');
    }

    public function testSilentServerFailsTheReadAtItsDeadline(): void
    {
        $server = $this->startServer($this->projectRoot(self::PROJECT));

        $this->expectException(FrameNotReceivedException::class);
        $server->readMessage(deadline: 0.1);
    }
}
