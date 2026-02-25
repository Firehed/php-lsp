<?php

declare(strict_types=1);

namespace Firehed\PhpLsp\Tests\Protocol;

use Firehed\PhpLsp\Protocol\NotificationMessage;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(NotificationMessage::class)]
class NotificationMessageTest extends TestCase
{
    public function testParseValidNotification(): void
    {
        $data = [
            'jsonrpc' => '2.0',
            'method' => 'initialized',
            'params' => [],
        ];

        $notification = NotificationMessage::fromArray($data);

        self::assertSame('initialized', $notification->method);
        self::assertSame([], $notification->params);
    }

    public function testParseNotificationWithoutParams(): void
    {
        $data = [
            'jsonrpc' => '2.0',
            'method' => 'exit',
        ];

        $notification = NotificationMessage::fromArray($data);

        self::assertSame('exit', $notification->method);
        self::assertNull($notification->params);
    }

    public function testIsNotification(): void
    {
        $data = [
            'jsonrpc' => '2.0',
            'method' => 'initialized',
        ];

        $notification = NotificationMessage::fromArray($data);

        self::assertTrue($notification->isNotification());
    }
}
