<?php

declare(strict_types=1);

namespace Firehed\PhpLsp\Tests\Protocol;

use Firehed\PhpLsp\Protocol\InitializeResult;
use Firehed\PhpLsp\ServerInfo;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(InitializeResult::class)]
class InitializeResultTest extends TestCase
{
    public function testSerializesToTheLspResultShape(): void
    {
        $result = new InitializeResult(
            capabilities: [
                'positionEncoding' => 'utf-16',
                'textDocumentSync' => [
                    'openClose' => true,
                    'change' => 1,
                    'save' => false,
                ],
                'definitionProvider' => true,
                'hoverProvider' => true,
                'signatureHelpProvider' => [
                    'triggerCharacters' => ['('],
                ],
                'completionProvider' => [
                    'triggerCharacters' => ['>'],
                ],
            ],
            serverInfo: new ServerInfo('php-lsp', '0.1.0'),
        );

        $encoded = json_encode($result, JSON_THROW_ON_ERROR);
        $decoded = json_decode($encoded, true, flags: JSON_THROW_ON_ERROR);

        self::assertSame([
            'capabilities' => [
                'positionEncoding' => 'utf-16',
                'textDocumentSync' => [
                    'openClose' => true,
                    'change' => 1,
                    'save' => false,
                ],
                'definitionProvider' => true,
                'hoverProvider' => true,
                'signatureHelpProvider' => [
                    'triggerCharacters' => ['('],
                ],
                'completionProvider' => [
                    'triggerCharacters' => ['>'],
                ],
            ],
            'serverInfo' => [
                'name' => 'php-lsp',
                'version' => '0.1.0',
            ],
        ], $decoded, 'the wire shape must match [LSP] InitializeResult');
    }
}
