<?php

declare(strict_types=1);

namespace Firehed\PhpLsp\Tests\Knowledge;

use Firehed\PhpLsp\Document\TextDocument;
use Firehed\PhpLsp\Domain\FileUri;
use Firehed\PhpLsp\Knowledge\DeclarationSourceInterface;

/**
 * Records the path of every document it is asked about, then answers from the
 * real declarations, so a backend under test indexes real symbols while each
 * scan it requests is observable.
 */
final class RecordingDeclarationSource implements DeclarationSourceInterface
{
    /** @var list<string> */
    public private(set) array $scanned = [];

    public function __construct(private readonly DeclarationSourceInterface $inner)
    {
    }

    public function declarationsIn(TextDocument $document): array
    {
        $this->scanned[] = FileUri::toPath($document->uri);

        return $this->inner->declarationsIn($document);
    }
}
