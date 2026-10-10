<?php

declare(strict_types=1);

namespace Firehed\PhpLsp\Parser\SyntaxSource;

use Firehed\PhpLsp\Document\TextDocument;
use Firehed\PhpLsp\Parser\ParsedDocument;

/**
 * The one-route composite for {@see SyntaxSourceInterface}. Members are asked
 * in order — primary parse, then skeleton fallback — and the first non-empty
 * result wins; the empty tree is returned only when every member returned it,
 * so a fallback reaches consumers exactly when the earlier members had nothing
 * to say (RFC 1 §4.11).
 */
final class CompositeSyntaxSource implements SyntaxSourceInterface
{
    /** @var list<SyntaxSourceInterface> */
    private readonly array $sources;

    public function __construct(
        PhpParserSyntaxSource $primary,
        SkeletonSyntaxSource $skeleton,
    ) {
        $this->sources = [$primary, $skeleton];
    }

    public function parse(TextDocument $document): ParsedDocument
    {
        foreach ($this->sources as $source) {
            $parsed = $source->parse($document);
            if ($parsed->tree !== []) {
                return $parsed;
            }
        }
        return new ParsedDocument($document, []);
    }
}
