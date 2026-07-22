<?php

declare(strict_types=1);

namespace Firehed\PhpLsp\Tests\Capability;

use Firehed\PhpLsp\Capability\CapabilityNegotiator;
use Firehed\PhpLsp\Capability\SessionCapabilities;
use Firehed\PhpLsp\Protocol\MarkupKind;
use Firehed\PhpLsp\Protocol\RequestMessage;
use Firehed\PhpLsp\ServerInfo;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
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

        self::assertSame(
            [
                'textDocumentSync',
                'definitionProvider',
                'hoverProvider',
                'signatureHelpProvider',
                'completionProvider',
            ],
            array_keys($capabilities),
            'RFC 1 §4.8 forbids advertising a capability the server does not implement; '
                . 'extend this list only together with the handler that answers the method',
        );
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
    #[DataProvider('hoverMarkupKindCases')]
    public function testHoverMarkupKindIsNegotiated(array $clientCapabilities, MarkupKind $expected): void
    {
        $capabilities = self::resolve(self::initializeWith($clientCapabilities));

        self::assertSame(
            $expected,
            $capabilities->hoverMarkupKind,
            'contentFormat order describes the client preference, per [LSP] HoverClientCapabilities',
        );
    }

    /**
     * @param array<array-key, mixed> $clientCapabilities
     */
    #[DataProvider('snippetSupportCases')]
    public function testSnippetSupportIsRead(array $clientCapabilities, bool $expected): void
    {
        $capabilities = self::resolve(self::initializeWith($clientCapabilities));

        self::assertSame(
            $expected,
            $capabilities->snippetSupport,
            'snippetSupport is only true when the client declares it as a boolean true',
        );
    }

    public function testMissingParamsResolveToDefaults(): void
    {
        $message = new RequestMessage(id: 1, method: 'initialize', params: null);

        self::assertEquals(
            new SessionCapabilities(),
            self::resolve($message),
            'a client that sends no params must be served the default configuration',
        );
    }

    public function testNonArrayCapabilitiesResolveToDefaults(): void
    {
        $message = new RequestMessage(id: 1, method: 'initialize', params: ['capabilities' => 'nonsense']);

        self::assertEquals(
            new SessionCapabilities(),
            self::resolve($message),
            'a malformed capabilities value must degrade to defaults, not crash negotiation',
        );
    }

    /**
     * @codeCoverageIgnore
     *
     * @return iterable<string, array{array<array-key, mixed>, MarkupKind}>
     */
    public static function hoverMarkupKindCases(): iterable
    {
        yield 'no capabilities declared' => [[], MarkupKind::PlainText];
        yield 'no hover capability' => [['textDocument' => []], MarkupKind::PlainText];
        yield 'no contentFormat' => [['textDocument' => ['hover' => []]], MarkupKind::PlainText];
        yield 'empty contentFormat' => [self::hover([]), MarkupKind::PlainText];
        yield 'markdown preferred' => [self::hover(['markdown', 'plaintext']), MarkupKind::Markdown];
        yield 'plaintext preferred' => [self::hover(['plaintext', 'markdown']), MarkupKind::PlainText];
        yield 'unsupported kind skipped' => [self::hover(['$reserved', 'markdown']), MarkupKind::Markdown];
        yield 'non-string entry skipped' => [self::hover([42, 'markdown']), MarkupKind::Markdown];
        yield 'contentFormat not a list' => [
            ['textDocument' => ['hover' => ['contentFormat' => 'markdown']]],
            MarkupKind::PlainText,
        ];
        yield 'textDocument not a map' => [['textDocument' => 'nonsense'], MarkupKind::PlainText];
    }

    /**
     * @codeCoverageIgnore
     *
     * @return iterable<string, array{array<array-key, mixed>, bool}>
     */
    public static function snippetSupportCases(): iterable
    {
        yield 'no capabilities declared' => [[], false];
        yield 'no completion capability' => [['textDocument' => []], false];
        yield 'no completionItem capability' => [['textDocument' => ['completion' => []]], false];
        yield 'declared true' => [self::completionItem(['snippetSupport' => true]), true];
        yield 'declared false' => [self::completionItem(['snippetSupport' => false]), false];
        yield 'declared as a non-boolean' => [self::completionItem(['snippetSupport' => 'yes']), false];
    }

    /**
     * @codeCoverageIgnore
     *
     * @param list<mixed> $contentFormat
     *
     * @return array<array-key, mixed>
     */
    private static function hover(array $contentFormat): array
    {
        return ['textDocument' => ['hover' => ['contentFormat' => $contentFormat]]];
    }

    /**
     * @codeCoverageIgnore
     *
     * @param array<string, mixed> $completionItem
     *
     * @return array<array-key, mixed>
     */
    private static function completionItem(array $completionItem): array
    {
        return ['textDocument' => ['completion' => ['completionItem' => $completionItem]]];
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

    private static function resolve(RequestMessage $message): SessionCapabilities
    {
        $negotiator = new CapabilityNegotiator(new ServerInfo('test', '1.0'));
        $negotiator->negotiate($message);

        return $negotiator->getSessionCapabilities();
    }
}
