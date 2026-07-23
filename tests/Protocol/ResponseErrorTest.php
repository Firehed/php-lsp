<?php

declare(strict_types=1);

namespace Firehed\PhpLsp\Tests\Protocol;

use Firehed\PhpLsp\Protocol\ResponseError;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(ResponseError::class)]
class ResponseErrorTest extends TestCase
{
    public function testServerNotInitializedUsesTheLspErrorCode(): void
    {
        $error = ResponseError::serverNotInitialized();

        self::assertSame(
            -32002,
            $error->code,
            'ServerNotInitialized is -32002 per LSP "Server lifecycle"',
        );
    }
}
