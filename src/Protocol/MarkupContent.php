<?php

declare(strict_types=1);

namespace Firehed\PhpLsp\Protocol;

/**
 * A `MarkupContent` result literal per [LSP] "Basic JSON Structures": a value
 * paired with the {@see MarkupKind} the client should render it as. The kind is
 * chosen from the client's declared support (RFC 1 §4.8), so a plaintext-only
 * client is never sent markdown.
 *
 * @phpstan-type LspMarkupContent array{kind: string, value: string}
 */
final readonly class MarkupContent
{
    public function __construct(
        public MarkupKind $kind,
        public string $value,
    ) {
    }

    /**
     * @return LspMarkupContent
     */
    public function toArray(): array
    {
        return [
            'kind' => $this->kind->value,
            'value' => $this->value,
        ];
    }
}
