<?php

declare(strict_types=1);

namespace Firehed\PhpLsp\Parser\SyntaxSource;

use Firehed\PhpLsp\Document\TextDocument;
use Firehed\PhpLsp\Parser\ParsedDocument;

/**
 * Content-keyed memo around one {@see SyntaxSourceInterface}, discarded at the LSP
 * message boundary through {@see MessageScopedInterface}. Within one handled message a
 * document parses at most once; different content is a different key, so no
 * invalidation rule has to be got right. Discarding it at the message boundary
 * is what keeps it request-scoped rather than a standing cache.
 */
final class MemoizingSyntaxSource implements SyntaxSourceInterface, MessageScopedInterface
{
    /**
     * Content => the tree it produced, for the message being handled. Keyed
     * by content, so array-key: PHP casts an integer-like content string to
     * an int key, and the memo neither notices nor cares.
     *
     * @var array<array-key, array<\PhpParser\Node\Stmt>>
     */
    private array $memo = [];

    public function __construct(
        private readonly SyntaxSourceInterface $inner,
    ) {
    }

    public function endMessage(): void
    {
        $this->memo = [];
    }

    public function parse(TextDocument $document): ParsedDocument
    {
        $content = $document->getContent();

        if (!array_key_exists($content, $this->memo)) {
            $this->memo[$content] = $this->inner->parse($document)->tree;
        }

        return new ParsedDocument($document, $this->memo[$content]);
    }
}
