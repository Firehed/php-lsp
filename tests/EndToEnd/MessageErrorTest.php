<?php

declare(strict_types=1);

namespace Firehed\PhpLsp\Tests\EndToEnd;

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
