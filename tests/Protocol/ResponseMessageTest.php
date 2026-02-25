<?php

declare(strict_types=1);

namespace Firehed\PhpLsp\Tests\Protocol;

use Firehed\PhpLsp\Protocol\ResponseError;
use Firehed\PhpLsp\Protocol\ResponseMessage;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

#[CoversClass(ResponseMessage::class)]
#[CoversClass(ResponseError::class)]
class ResponseMessageTest extends TestCase
{
    public function testSuccessResponseJsonFormat(): void
    {
        $response = ResponseMessage::success(1, ['capabilities' => []]);

        $decoded = $this->encodeAndDecode($response);

        self::assertSame('2.0', $decoded['jsonrpc']);
        self::assertSame(1, $decoded['id']);
        self::assertSame(['capabilities' => []], $decoded['result']);
        self::assertArrayNotHasKey('error', $decoded);
    }

    public function testSuccessResponseWithStringId(): void
    {
        $response = ResponseMessage::success('req-123', ['data' => 'value']);

        $decoded = $this->encodeAndDecode($response);

        self::assertSame('2.0', $decoded['jsonrpc']);
        self::assertSame('req-123', $decoded['id']);
        self::assertSame(['data' => 'value'], $decoded['result']);
    }

    public function testSuccessResponseWithNullResult(): void
    {
        $response = ResponseMessage::success(1, null);

        $decoded = $this->encodeAndDecode($response);

        self::assertSame('2.0', $decoded['jsonrpc']);
        self::assertSame(1, $decoded['id']);
        self::assertNull($decoded['result']);
        self::assertArrayNotHasKey('error', $decoded);
    }

    public function testErrorResponseJsonFormat(): void
    {
        $error = new ResponseError(-32601, 'Method not found');
        $response = ResponseMessage::error(1, $error);

        $decoded = $this->encodeAndDecode($response);

        self::assertSame('2.0', $decoded['jsonrpc']);
        self::assertSame(1, $decoded['id']);
        self::assertArrayNotHasKey('result', $decoded);
        self::assertIsArray($decoded['error']);
        self::assertSame(-32601, $decoded['error']['code']);
        self::assertSame('Method not found', $decoded['error']['message']);
        self::assertArrayNotHasKey('data', $decoded['error']);
    }

    public function testErrorResponseWithData(): void
    {
        $error = new ResponseError(-32602, 'Invalid params', ['expected' => 'string']);
        $response = ResponseMessage::error(2, $error);

        $decoded = $this->encodeAndDecode($response);

        self::assertIsArray($decoded['error']);
        self::assertSame(-32602, $decoded['error']['code']);
        self::assertSame('Invalid params', $decoded['error']['message']);
        self::assertSame(['expected' => 'string'], $decoded['error']['data']);
    }

    public function testErrorResponseWithNullId(): void
    {
        $error = new ResponseError(-32700, 'Parse error');
        $response = ResponseMessage::error(null, $error);

        $decoded = $this->encodeAndDecode($response);

        self::assertSame('2.0', $decoded['jsonrpc']);
        self::assertNull($decoded['id']);
        self::assertArrayHasKey('error', $decoded);
    }

    #[DataProvider('errorCodesProvider')]
    public function testStandardErrorCodes(int $code, string $expectedMessage): void
    {
        $error = ResponseError::$expectedMessage();
        assert($error instanceof ResponseError);

        self::assertSame($code, $error->code);
    }

    /**
     * @return array<string, array{int, string}>
     */
    public static function errorCodesProvider(): array
    {
        return [
            'parseError' => [-32700, 'parseError'],
            'invalidRequest' => [-32600, 'invalidRequest'],
            'methodNotFound' => [-32601, 'methodNotFound'],
            'invalidParams' => [-32602, 'invalidParams'],
            'internalError' => [-32603, 'internalError'],
        ];
    }

    /**
     * @return array<array-key, mixed>
     */
    private function encodeAndDecode(ResponseMessage $response): array
    {
        $json = json_encode($response, JSON_THROW_ON_ERROR);
        $decoded = json_decode($json, true, flags: JSON_THROW_ON_ERROR);
        assert(is_array($decoded));
        return $decoded;
    }
}
