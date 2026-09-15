<?php

declare(strict_types=1);

namespace Firehed\PhpLsp\Completion;

use Firehed\PhpLsp\Document\TextDocument;

/**
 * The position a completion source is asked about, plus the text-derived views
 * a source may need. Text-before-cursor and the classifier output are
 * memoized: a source that never reads them pays nothing; several sources that
 * read them share one computation.
 */
final class CompletionRequest
{
    private ?string $textBeforeCursor = null;
    private ?CompletionClassification $classification = null;

    public function __construct(
        public readonly TextDocument $document,
        public readonly int $line,
        public readonly int $character,
    ) {
    }

    public function textBeforeCursor(): string
    {
        return $this->textBeforeCursor ??= $this->document->textBeforeCursor($this->line, $this->character);
    }

    public function classification(): CompletionClassification
    {
        return $this->classification ??= CompletionClassifier::classify($this->textBeforeCursor());
    }
}
