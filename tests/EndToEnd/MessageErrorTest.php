<?php

declare(strict_types=1);

namespace Firehed\PhpLsp\Tests\EndToEnd;

use Firehed\PhpLsp\Tests\EndToEnd\Client\ErrorCode;
use Firehed\PhpLsp\Tests\EndToEnd\Client\LspClient;
use Firehed\PhpLsp\Tests\EndToEnd\Client\ServerProcess;
use Firehed\PhpLsp\Tests\EndToEnd\Client\StartsServerTrait;
use Firehed\PhpLsp\Tests\EndToEnd\Session\ClientCapabilities;
use Firehed\PhpLsp\Tests\EndToEnd\Session\Feature;
use Firehed\PhpLsp\Tests\EndToEnd\Session\Session;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use stdClass;

/**
 * Messages the server cannot act on are answered, and the session carries on.
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

    /**
     * Today the answer is an empty result. JSON-RPC 2.0 §5.1 also defines
     * InvalidParams (-32602) for this.
     */
    #[DataProvider('malformedPositionParams')]
    public function testMalformedPositionParamsAreAnsweredWithNoResult(string $params): void
    {
        $decoded = json_decode($params, flags: JSON_THROW_ON_ERROR);
        assert($decoded instanceof stdClass);

        $response = $this->client->request(Feature::Definition->value, $decoded);
        $this->session->end();

        self::assertNull($response->error, 'malformed parameters are not answered with an error');
        self::assertNull($response->result, 'malformed parameters are answered with no result');
        self::assertSame(0, $this->server->waitForExit(), 'the session carries on');
    }

    /**
     * Parameters are checked before the document is read, so the URI need not
     * name a real file.
     *
     * @return iterable<string, array{string}>
     */
    public static function malformedPositionParams(): iterable
    {
        yield 'textDocument is not an object' => ['{"textDocument":"x","position":{"line":0,"character":0}}'];
        yield 'uri is not a string' => ['{"textDocument":{"uri":123},"position":{"line":0,"character":0}}'];
        yield 'position is not an object' => ['{"textDocument":{"uri":"file:///x.php"},"position":"x"}'];
        yield 'line is not an integer' => [
            '{"textDocument":{"uri":"file:///x.php"},"position":{"line":"x","character":0}}',
        ];
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
