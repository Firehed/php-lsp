<?php

declare(strict_types=1);

namespace Firehed\PhpLsp\Tests\Handler;

use Firehed\PhpLsp\Cache\InvalidatableInterface;
use Firehed\PhpLsp\Capability\SessionCapabilitiesProviderInterface;
use Firehed\PhpLsp\Completion\CompletionSourceInterface;
use Firehed\PhpLsp\Document\DocumentManagerInterface;
use Firehed\PhpLsp\Document\DocumentSourceInterface;
use Firehed\PhpLsp\Handler\CompletionHandler;
use Firehed\PhpLsp\Handler\CompositeHandler;
use Firehed\PhpLsp\Handler\DefinitionHandler;
use Firehed\PhpLsp\Handler\DidChangeWatchedFilesHandler;
use Firehed\PhpLsp\Handler\HoverHandler;
use Firehed\PhpLsp\Handler\SignatureHelpHandler;
use Firehed\PhpLsp\Handler\TextDocumentSyncHandler;
use Firehed\PhpLsp\Knowledge\SymbolSinkInterface;
use Firehed\PhpLsp\Resolution\CodeResolverInterface;
use Firehed\PhpLsp\Resolution\Position\CallAtPositionInterface;
use Firehed\PhpLsp\Resolution\ResolvedSymbolPresenterInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Each member is built over its own probe: an invalidator for the members that
 * invalidate, a document source for the members that read the open document.
 * A message that reaches a member trips that member's probe.
 *
 * @phpstan-type Probes array{
 *   sync: InvalidatableInterface,
 *   watchedFiles: InvalidatableInterface,
 *   definition: DocumentSourceInterface,
 *   hover: DocumentSourceInterface,
 *   signatureHelp: DocumentSourceInterface,
 *   completion: DocumentSourceInterface,
 * }
 */
#[CoversClass(CompositeHandler::class)]
final class CompositeHandlerTest extends TestCase
{
    use BuildsHandlerRequestsTrait;

    private const string URI = 'file:///t.php';

    public function testDoesNotSupportWhatNoMemberSupports(): void
    {
        $composite = self::composite(self::stubProbes());

        self::assertFalse($composite->supports('initialize'), 'lifecycle messages belong to no member');
        self::assertFalse($composite->supports('unknown/method'), 'no member handles an unknown method');
    }

    /**
     * A message that reached the wrong member, or more than one, trips a probe
     * that should have stayed quiet.
     *
     * @param ?array<string, mixed> $params null for a position request
     * @param key-of<Probes> $member
     */
    #[DataProvider('routes')]
    public function testHandleReachesOnlyTheMemberThatSupportsTheMethod(
        string $method,
        ?array $params,
        string $member,
    ): void {
        $invalidator = function (string $name) use ($member): InvalidatableInterface {
            $probe = $this->createMock(InvalidatableInterface::class);
            $probe->expects($name === $member ? $this->once() : $this->never())->method('invalidate');
            return $probe;
        };
        $reader = function (string $name) use ($member): DocumentSourceInterface {
            $probe = $this->createMock(DocumentSourceInterface::class);
            $probe->expects($name === $member ? $this->once() : $this->never())->method('read');
            return $probe;
        };

        $composite = self::composite([
            'sync' => $invalidator('sync'),
            'watchedFiles' => $invalidator('watchedFiles'),
            'definition' => $reader('definition'),
            'hover' => $reader('hover'),
            'signatureHelp' => $reader('signatureHelp'),
            'completion' => $reader('completion'),
        ]);

        self::assertTrue($composite->supports($method), "a member handles {$method}");
        $composite->handle(
            $params === null ? $this->positionRequest($method, self::URI) : $this->request($method, $params),
        );
    }

    /**
     * One method per member: which methods a member claims is that member's
     * own tests' concern.
     *
     * @return iterable<string, array{string, ?array<string, mixed>, key-of<Probes>}>
     */
    public static function routes(): iterable
    {
        yield 'didClose' => ['textDocument/didClose', ['textDocument' => ['uri' => self::URI]], 'sync'];
        yield 'didChangeWatchedFiles' => [
            'workspace/didChangeWatchedFiles',
            ['changes' => [['uri' => self::URI, 'type' => 2]]],
            'watchedFiles',
        ];
        yield 'definition' => ['textDocument/definition', null, 'definition'];
        yield 'hover' => ['textDocument/hover', null, 'hover'];
        yield 'signatureHelp' => ['textDocument/signatureHelp', null, 'signatureHelp'];
        yield 'completion' => ['textDocument/completion', null, 'completion'];
    }

    public function testHandlingAMethodNoMemberSupportsFails(): void
    {
        $this->expectException(\LogicException::class);

        self::composite(self::stubProbes())->handle($this->request('unknown/method'));
    }

    /**
     * @return Probes
     */
    private static function stubProbes(): array
    {
        return [
            'sync' => self::createStub(InvalidatableInterface::class),
            'watchedFiles' => self::createStub(InvalidatableInterface::class),
            'definition' => self::createStub(DocumentSourceInterface::class),
            'hover' => self::createStub(DocumentSourceInterface::class),
            'signatureHelp' => self::createStub(DocumentSourceInterface::class),
            'completion' => self::createStub(DocumentSourceInterface::class),
        ];
    }

    /**
     * @param Probes $probes
     */
    private static function composite(array $probes): CompositeHandler
    {
        return new CompositeHandler(
            new TextDocumentSyncHandler(
                self::createStub(DocumentManagerInterface::class),
                self::createStub(SymbolSinkInterface::class),
                $probes['sync'],
            ),
            new DidChangeWatchedFilesHandler($probes['watchedFiles']),
            new DefinitionHandler($probes['definition'], self::createStub(CodeResolverInterface::class)),
            new HoverHandler(
                $probes['hover'],
                self::createStub(CodeResolverInterface::class),
                self::createStub(SessionCapabilitiesProviderInterface::class),
                self::createStub(ResolvedSymbolPresenterInterface::class),
            ),
            new SignatureHelpHandler(
                $probes['signatureHelp'],
                self::createStub(CallAtPositionInterface::class),
                self::createStub(ResolvedSymbolPresenterInterface::class),
            ),
            new CompletionHandler($probes['completion'], self::createStub(CompletionSourceInterface::class)),
        );
    }
}
