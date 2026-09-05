<?php

declare(strict_types=1);

namespace Firehed\PhpLsp\Resolution;

use Firehed\PhpLsp\Document\TextDocument;
use Firehed\PhpLsp\Domain\DeclaredSymbol;
use Firehed\PhpLsp\Domain\FileUri;
use Firehed\PhpLsp\Knowledge\DeclarationScanner;
use Firehed\PhpLsp\Knowledge\DeclarationSymbolInfoFactory;
use Firehed\PhpLsp\Knowledge\TextSymbolExtractor;
use Firehed\PhpLsp\Parser\SyntaxSource\SyntaxSource;

/**
 * The default {@see TextSymbolExtractor}: the third `DeclaredSymbol` producer
 * (RFC 1 §5.3), alongside the AST- and reflection-based paths.
 *
 * The regex home moved to the skeleton {@see SyntaxSource} in step-37, so this
 * extractor now runs the skeleton over the document and feeds its tree to the
 * same {@see DeclarationScanner} and {@see DeclarationSymbolInfoFactory} the
 * AST tier uses. Once step-38 lands, the sink calls those directly and this
 * class is deleted.
 */
final class DefaultTextSymbolExtractor implements TextSymbolExtractor
{
    public function __construct(
        private readonly SyntaxSource $skeleton,
        private readonly DeclarationScanner $scanner,
        private readonly DeclarationSymbolInfoFactory $infoFactory,
    ) {
    }

    /**
     * @return list<DeclaredSymbol>
     */
    public function extract(string $content, string $filePath): array
    {
        $document = new TextDocument(FileUri::fromPath($filePath), 'php', 0, $content);
        $tree = $this->skeleton->parse($document);
        if ($tree === []) {
            return [];
        }
        return $this->infoFactory->allIn($this->scanner->scan($tree), $filePath);
    }
}
