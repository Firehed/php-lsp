<?php

declare(strict_types=1);

namespace Firehed\PhpLsp\Parser;

use Firehed\PhpLsp\Document\TextDocument;
use PhpParser\Node\Stmt;
use PhpParser\Parser;
use PhpParser\ParserFactory;

final class ParserService
{
    private Parser $parser;

    public function __construct()
    {
        $this->parser = (new ParserFactory())->createForNewestSupportedVersion();
    }

    /**
     * @return array<Stmt>|null
     */
    public function parse(TextDocument $document): ?array
    {
        try {
            return $this->parser->parse($document->getContent()) ?? [];
        } catch (\PhpParser\Error) {
            return null;
        }
    }
}
