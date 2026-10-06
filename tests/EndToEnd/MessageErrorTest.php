<?php

declare(strict_types=1);

namespace Firehed\PhpLsp\Tests\EndToEnd;

use Firehed\PhpLsp\Tests\EndToEnd\Client\ErrorCode;
use Firehed\PhpLsp\Tests\EndToEnd\Client\LspClient;
use Firehed\PhpLsp\Tests\EndToEnd\Client\ServerProcess;
use Firehed\PhpLsp\Tests\EndToEnd\Client\StartsServerTrait;
use Firehed\PhpLsp\Tests\EndToEnd\Session\ClientCapabilities;
use Firehed\PhpLsp\Tests\EndToEnd\Session\Session;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\TestCase;

/**
 * Messages the server cannot act on are answered with an error, and the
 * session carries on.
 */
#[CoversNothing]
final class MessageErrorTest extends TestCase
{
    use StartsServerTrait;

    private ServerProcess $server;

    private LspClient $client;

    private Session $session;

    protected function setUp(): void
    {
        $projectRoot = $this->projectRoot('tests/Fixtures');
        $this->server = $this->startServer($projectRoot);
        $this->client = new LspClient($this->server);
        $this->session = new Session($this->client, $projectRoot);
        $this->session->start(new ClientCapabilities());
    }

    public function testInvalidRequestIsAnsweredAtItsId(): void
    {
        // JSON-RPC 2.0 §4: `method` is a string.
        $this->server->writeFrame('{"jsonrpc":"2.0","id":6,"method":42}');
        $response = $this->server->readMessage();
        $this->session->end();

        self::assertSame(
            ErrorCode::InvalidRequest->value,
            $response->error?->code,
            'a request that is JSON but not a valid request is an InvalidRequest error',
        );
        self::assertSame(6, $response->id, 'an id that can be detected is answered');
        self::assertSame(0, $this->server->waitForExit(), 'the session carries on after the error');
    }

    public function testUnparsableFrameIsAParseError(): void
    {
        $this->server->writeFrame('this is not json');
        $response = $this->server->readMessage();
        $this->session->end();

        self::assertSame(
            ErrorCode::ParseError->value,
            $response->error?->code,
            'a body that is not JSON is a ParseError',
        );
        self::assertNull($response->id, 'an id that cannot be detected is answered as null (JSON-RPC 2.0 §5)');
        self::assertSame(0, $this->server->waitForExit(), 'the session carries on after the error');
    }

    public function testUnknownMethodIsMethodNotFound(): void
    {
        $response = $this->client->request('unknown/method');
        $this->session->end();

        self::assertSame(
            ErrorCode::MethodNotFound->value,
            $response->error?->code,
            'a method the server does not handle is a MethodNotFound error',
        );
        self::assertSame(0, $this->server->waitForExit(), 'the session carries on after the error');
    }
}
