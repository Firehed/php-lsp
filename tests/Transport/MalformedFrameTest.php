<?php

declare(strict_types=1);

namespace Firehed\PhpLsp\Tests\Transport;

use Firehed\PhpLsp\Protocol\ErrorCode;
use Firehed\PhpLsp\Protocol\ResponseError;
use Firehed\PhpLsp\Transport\MalformedFrame;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(MalformedFrame::class)]
final class MalformedFrameTest extends TestCase
{
    public function testKeepsTheErrorAndTheIdToAnswerAt(): void
    {
        $error = new ResponseError(ErrorCode::ParseError, 'Parse error');
        $frame = new MalformedFrame($error, 7);

        self::assertSame($error, $frame->error, 'the error to answer with is kept');
        self::assertSame(7, $frame->id, 'the recovered id is kept');
    }

    public function testHasNoIdWhenNoneWasRecovered(): void
    {
        self::assertNull(
            (new MalformedFrame(new ResponseError(ErrorCode::ParseError, 'Parse error')))->id,
            'JSON-RPC answers at a null id when none could be read',
        );
    }
}
