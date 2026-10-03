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

    private const string PROJECT = 'tests/Fixtures';

    public function testUnknownMethodIsMethodNotFound(): void
    {
        $projectRoot = $this->projectRoot(self::PROJECT);
        $server = $this->startServer($projectRoot);
        $client = new LspClient($server);
        $session = new Session($client, $projectRoot);
        $session->start(new ClientCapabilities());

        $response = $client->request('unknown/method');
        $session->end();

        self::assertSame(
            ErrorCode::MethodNotFound->value,
            $response->error?->code,
            'a method the server does not handle is a MethodNotFound error',
        );
        self::assertSame(0, $server->waitForExit(), 'the session carries on after the error');
    }
}
