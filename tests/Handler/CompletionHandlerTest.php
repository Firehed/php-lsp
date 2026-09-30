<?php

declare(strict_types=1);

namespace Firehed\PhpLsp\Tests\Handler;

use Firehed\PhpLsp\Completion\CompletionSourceInterface;
use Firehed\PhpLsp\Document\DocumentSourceInterface;
use Firehed\PhpLsp\Document\TextDocument;
use Firehed\PhpLsp\Handler\CompletionHandler;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(CompletionHandler::class)]
class CompletionHandlerTest extends TestCase
{
    use BuildsHandlerRequestsTrait;
    use StubsCollaboratorsTrait;

    private const METHOD = 'textDocument/completion';
    private const URI = 'file:///test.php';

    // Matches CompletionHandler::RESULT_LIMIT.
    private const RESULT_LIMIT = 100;

    public function testSupports(): void
    {
        $handler = new CompletionHandler(
            self::createStub(DocumentSourceInterface::class),
            self::createStub(CompletionSourceInterface::class),
        );

        self::assertTrue(
            $handler->supports('textDocument/completion'),
            'the handler claims its own method',
        );
        self::assertFalse(
            $handler->supports('textDocument/hover'),
            'the handler does not claim any other method',
        );
    }

    public function testHandleReturnsNullWhenPositionParamsAreMalformed(): void
    {
        $documents = $this->createMock(DocumentSourceInterface::class);
        $documents->expects($this->never())->method('read');
        $source = $this->createMock(CompletionSourceInterface::class);
        $source->expects($this->never())->method('find');

        $handler = new CompletionHandler($documents, $source);
        $result = $handler->handle(
            $this->request(self::METHOD, ['textDocument' => 'not-an-object']),
        );

        self::assertNull($result, 'a malformed textDocument shape short-circuits before any source call');
    }

    public function testHandleReturnsNullWhenDocumentIsNotOpen(): void
    {
        $documents = $this->createMock(DocumentSourceInterface::class);
        $documents
            ->expects($this->once())
            ->method('read')
            ->with(self::URI)
            ->willReturn(null);
        $source = $this->createMock(CompletionSourceInterface::class);
        $source->expects($this->never())->method('find');

        $handler = new CompletionHandler($documents, $source);
        $result = $handler->handle($this->positionRequest(self::METHOD, self::URI));

        self::assertNull($result, 'an unopened document short-circuits before the source is consulted');
    }

    public function testHandleReturnsAnEmptyCompleteListWhenTheSourceHasNothingToOffer(): void
    {
        $handler = new CompletionHandler(
            $this->documentsReturning(new TextDocument(self::URI, 'php', 1, '<?php ')),
            $this->sourceReturning(null),
        );

        $result = $handler->handle($this->positionRequest(self::METHOD, self::URI));

        self::assertSame(
            ['isIncomplete' => false, 'items' => []],
            $result,
            'a null from the source becomes an empty complete list, not a null result',
        );
    }

    public function testHandleReturnsResultsUnmodifiedWhenUnderTheLimit(): void
    {
        $items = [
            ['label' => 'zebra'],
            ['label' => 'apple'],
            ['label' => 'mango'],
        ];
        $handler = new CompletionHandler(
            $this->documentsReturning(new TextDocument(self::URI, 'php', 1, '<?php ')),
            $this->sourceReturning($items),
        );

        $result = $handler->handle($this->positionRequest(self::METHOD, self::URI));

        self::assertSame(
            ['isIncomplete' => false, 'items' => $items],
            $result,
            'a result below the cap is passed through in the source order, complete',
        );
    }

    public function testHandleSortsAndTruncatesAndMarksIncompleteWhenOverTheLimit(): void
    {
        // 101 items with labels that sort predictably: item-000 through item-100.
        // The cap drops the largest by label; alphabetic order puts item-100
        // ahead of item-099, so the item expected dropped is item-100.
        $items = [];
        for ($i = 100; $i >= 0; $i--) {
            $items[] = ['label' => sprintf('item-%03d', $i)];
        }

        $handler = new CompletionHandler(
            $this->documentsReturning(new TextDocument(self::URI, 'php', 1, '<?php ')),
            $this->sourceReturning($items),
        );

        $result = $handler->handle($this->positionRequest(self::METHOD, self::URI));

        self::assertIsArray($result);
        self::assertTrue(
            $result['isIncomplete'],
            'a truncated list must be marked incomplete so the client re-queries',
        );
        self::assertCount(self::RESULT_LIMIT, $result['items'], 'the cap is enforced');
        self::assertSame(
            'item-000',
            $result['items'][0]['label'],
            'sorting is alphabetic by label so the client sees a deterministic prefix',
        );
        $labels = array_column($result['items'], 'label');
        self::assertNotContains(
            'item-100',
            $labels,
            'the highest-sorting label is dropped when the cap trims the tail',
        );
    }

    public function testHandleSortsBySortTextWhenPresentAndFallsBackToLabel(): void
    {
        // 100 filler items whose labels sort predictably, plus one item whose
        // label would sort last but whose sortText forces it first. The mix
        // proves the comparator honors sortText where given and falls back to
        // label otherwise.
        $items = [['label' => 'z-would-be-last', 'sortText' => '000']];
        for ($i = 0; $i < self::RESULT_LIMIT; $i++) {
            $items[] = ['label' => sprintf('filler-%03d', $i)];
        }

        $handler = new CompletionHandler(
            $this->documentsReturning(new TextDocument(self::URI, 'php', 1, '<?php ')),
            $this->sourceReturning($items),
        );

        $result = $handler->handle($this->positionRequest(self::METHOD, self::URI));

        self::assertIsArray($result);
        self::assertCount(self::RESULT_LIMIT, $result['items'], 'the cap is enforced');
        self::assertSame(
            'z-would-be-last',
            $result['items'][0]['label'],
            'sortText overrides label so a source can rank its item ahead of an alphabetically earlier one',
        );
    }

    /**
     * @param list<array{label: string, sortText?: string}>|null $items
     */
    private function sourceReturning(?array $items): CompletionSourceInterface
    {
        $stub = self::createStub(CompletionSourceInterface::class);
        $stub->method('find')->willReturn($items);
        return $stub;
    }
}
