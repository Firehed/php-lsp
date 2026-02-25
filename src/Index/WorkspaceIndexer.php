<?php

declare(strict_types=1);

namespace Firehed\PhpLsp\Index;

use Firehed\PhpLsp\Document\TextDocument;
use Firehed\PhpLsp\Parser\ParserService;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;

final class WorkspaceIndexer
{
    public function __construct(
        private readonly ParserService $parser,
        private readonly SymbolExtractor $extractor,
        private readonly SymbolIndex $index,
    ) {
    }

    public function indexDirectory(string $path): void
    {
        if (!is_dir($path)) {
            return;
        }

        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($path, RecursiveDirectoryIterator::SKIP_DOTS),
        );

        /** @var SplFileInfo $file */
        foreach ($iterator as $file) {
            if (!$file->isFile()) {
                continue;
            }
            if ($file->getExtension() !== 'php') {
                continue;
            }

            // Skip vendor directory
            $realPath = $file->getRealPath();
            if ($realPath === false) {
                continue;
            }
            if (str_contains($realPath, '/vendor/')) {
                continue;
            }

            $this->indexFile($realPath);
        }
    }

    private function indexFile(string $path): void
    {
        $content = file_get_contents($path);
        if ($content === false) {
            return;
        }

        $uri = 'file://' . $path;
        $document = new TextDocument($uri, 'php', 0, $content);

        $ast = $this->parser->parse($document);
        if ($ast === null) {
            return;
        }

        $symbols = $this->extractor->extract($document, $ast);
        foreach ($symbols as $symbol) {
            $this->index->add($symbol);
        }
    }
}
