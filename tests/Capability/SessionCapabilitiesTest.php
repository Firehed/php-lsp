<?php

declare(strict_types=1);

namespace Firehed\PhpLsp\Tests\Capability;

use Firehed\PhpLsp\Capability\SessionCapabilities;
use Firehed\PhpLsp\Protocol\MarkupKind;
use Firehed\PhpLsp\Protocol\RequestMessage;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

#[CoversClass(SessionCapabilities::class)]
class SessionCapabilitiesTest extends TestCase
{
    public function testDefaultsAreTheSafeValues(): void
    {
        $capabilities = new SessionCapabilities();

        self::assertSame(
            MarkupKind::PlainText,
            $capabilities->hoverMarkupKind,
            'plaintext is the only markup every client renders, so it is the safe default',
        );
        self::assertFalse(
            $capabilities->snippetSupport,
            'snippet syntax is inserted literally by a client that cannot expand it',
        );
    }

    /**
     * @param array<array-key, mixed> $clientCapabilities
     */
    #[DataProvider('hoverMarkupKindCases')]
    public function testHoverMarkupKindIsNegotiated(array $clientCapabilities, MarkupKind $expected): void
    {
        $capabilities = SessionCapabilities::fromMessage(self::initializeWith($clientCapabilities));

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
        $capabilities = SessionCapabilities::fromMessage(self::initializeWith($clientCapabilities));

        self::assertSame(
            $expected,
            $capabilities->snippetSupport,
            'snippetSupport is only true when the client declares it as a boolean true',
        );
    }

    public function testMissingParamsResolveToDefaults(): void
    {
        $message = new RequestMessage(id: 1, method: 'initialize', params: null);

        $capabilities = SessionCapabilities::fromMessage($message);

        self::assertEquals(
            new SessionCapabilities(),
            $capabilities,
            'a client that sends no params must be served the default configuration',
        );
    }

    public function testNonArrayCapabilitiesResolveToDefaults(): void
    {
        $message = new RequestMessage(id: 1, method: 'initialize', params: ['capabilities' => 'nonsense']);

        $capabilities = SessionCapabilities::fromMessage($message);

        self::assertEquals(
            new SessionCapabilities(),
            $capabilities,
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
}
