<?php

declare(strict_types=1);

namespace Firehed\PhpLsp\Parser\SyntaxSource;

use Firehed\PhpLsp\Document\TextDocument;

/**
 * Content-keyed memo around one {@see SyntaxSource}, discarded at the LSP
 * message boundary through {@see MessageScoped}. Within one handled message a
 * document parses at most once; different content is a different key, so no
 * invalidation rule has to be got right. Discarding it at the message boundary
 * is what keeps it request-scoped rather than a standing cache the Step 0
 * spike declined (0002-execution-plan.md, Section 8.5).
 */
final class MemoizingSyntaxSource implements SyntaxSource, MessageScoped
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
        private readonly SyntaxSource $inner,
    ) {
    }

    public function endMessage(): void
    {
        $this->memo = [];
    }

    /**
     * @return array<\PhpParser\Node\Stmt>
     */
    public function parse(TextDocument $document): array
    {
        $content = $document->getContent();

        if (!array_key_exists($content, $this->memo)) {
            $this->memo[$content] = $this->inner->parse($document);
        }

        return $this->memo[$content];
    }
}
