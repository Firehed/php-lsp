<?php

declare(strict_types=1);

namespace Firehed\PhpLsp\Tests\Capability;

use Firehed\PhpLsp\Capability\CapabilityNegotiator;
use Firehed\PhpLsp\Capability\SessionCapabilities;
use Firehed\PhpLsp\Protocol\MarkupKind;
use Firehed\PhpLsp\Protocol\RequestMessage;
use Firehed\PhpLsp\ServerInfo;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(CapabilityNegotiator::class)]
class CapabilityNegotiatorTest extends TestCase
{
    public function testSessionCapabilitiesDefaultBeforeInitialize(): void
    {
        $negotiator = new CapabilityNegotiator(new ServerInfo('test', '1.0'));

        self::assertEquals(
            new SessionCapabilities(),
            $negotiator->getSessionCapabilities(),
            'components must have a usable value before initialize rather than a null to branch on',
        );
    }

    public function testNegotiateResolvesTheSessionCapabilitiesOnce(): void
    {
        $negotiator = new CapabilityNegotiator(new ServerInfo('test', '1.0'));

        $negotiator->negotiate(self::initializeWith([
            'textDocument' => [
                'hover' => ['contentFormat' => ['markdown', 'plaintext']],
                'completion' => ['completionItem' => ['snippetSupport' => true]],
            ],
        ]));

        $capabilities = $negotiator->getSessionCapabilities();

        self::assertSame(
            MarkupKind::Markdown,
            $capabilities->hoverMarkupKind,
            'the declared hover format must be readable without re-inspecting the initialize params',
        );
        self::assertTrue(
            $capabilities->snippetSupport,
            'the declared snippet support must be readable without re-inspecting the initialize params',
        );
    }

    public function testNegotiateReportsServerInfo(): void
    {
        $negotiator = new CapabilityNegotiator(new ServerInfo('php-lsp', '0.1.0'));

        $result = $negotiator->negotiate(self::initializeWith([]));

        self::assertSame('php-lsp', $result->serverInfo->name, 'serverInfo comes from the injected value');
        self::assertSame('0.1.0', $result->serverInfo->version, 'serverInfo comes from the injected value');
    }

    public function testAdvertisesTextDocumentSyncAsOptions(): void
    {
        $negotiator = new CapabilityNegotiator(new ServerInfo('test', '1.0'));

        $sync = $negotiator->negotiate(self::initializeWith([]))->capabilities['textDocumentSync'];

        self::assertTrue($sync['openClose'], 'the server tracks open and close notifications');
        self::assertSame(1, $sync['change'], 'the server only supports full-document sync (TextDocumentSyncKind.Full)');
        self::assertFalse($sync['save'], 'the server does not act on save notifications');
    }

    public function testAdvertisesOnlyImplementedProviders(): void
    {
        $negotiator = new CapabilityNegotiator(new ServerInfo('test', '1.0'));

        $capabilities = $negotiator->negotiate(self::initializeWith([]))->capabilities;

        self::assertTrue($capabilities['definitionProvider'], 'DefinitionHandler implements textDocument/definition');
        self::assertTrue($capabilities['hoverProvider'], 'HoverHandler implements textDocument/hover');
        self::assertSame(
            ['(', ','],
            $capabilities['signatureHelpProvider']['triggerCharacters'],
            'signature help re-fires as arguments are typed',
        );
    }

    public function testCompletionTriggerCharactersExcludeColon(): void
    {
        $negotiator = new CapabilityNegotiator(new ServerInfo('test', '1.0'));

        $capabilities = $negotiator->negotiate(self::initializeWith([]))->capabilities;
        $triggers = $capabilities['completionProvider']['triggerCharacters'];

        self::assertNotContains(':', $triggers, "':' fires prematurely on the first ':' of '::'");
        self::assertContains('>', $triggers, 'member access completion triggers on the > of ->');
        self::assertContains('$', $triggers, 'variable completion triggers on $');
        self::assertContains('\\', $triggers, 'namespaced class completion triggers on a separator');
    }

    /**
     * @param array<array-key, mixed> $clientCapabilities
     */
    private static function initializeWith(array $clientCapabilities): RequestMessage
    {
        return new RequestMessage(
            id: 1,
            method: 'initialize',
            params: ['capabilities' => $clientCapabilities],
        );
    }
}
