<?php

declare(strict_types=1);

namespace Firehed\PhpLsp\Tests\Protocol;

use Firehed\PhpLsp\Protocol\ServerInfo;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(ServerInfo::class)]
final class ServerInfoTest extends TestCase
{
    public function testToArrayIsTheWireShape(): void
    {
        self::assertSame(
            ['name' => 'php-lsp', 'version' => '1.2.3'],
            (new ServerInfo('php-lsp', '1.2.3'))->toArray(),
            '[LSP] InitializeResult.serverInfo has a name and a version',
        );
    }
}
