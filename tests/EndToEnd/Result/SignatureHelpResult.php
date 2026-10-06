<?php

declare(strict_types=1);

namespace Firehed\PhpLsp\Tests\EndToEnd\Result;

use PHPUnit\Framework\Assert;
use stdClass;

/**
 * What a signature help request answered.
 */
final readonly class SignatureHelpResult
{
    /**
     * @param Signature|null $active Null when there are no signatures.
     */
    public function __construct(
        public int $count,
        public ?Signature $active,
    ) {
    }

    /**
     * Decodes [LSP] textDocument/signatureHelp: SignatureHelp | null. The
     * active signature defaults to the first when unset or out of range.
     */
    public static function fromWire(mixed $result): self
    {
        if ($result === null) {
            return new self(0, null);
        }
        Assert::assertInstanceOf(stdClass::class, $result, 'a signature help answer is a SignatureHelp or null');
        $signatures = $result->signatures ?? null;
        Assert::assertIsList($signatures, 'SignatureHelp has a list of signatures');
        if ($signatures === []) {
            return new self(0, null);
        }
        $index = $result->activeSignature ?? 0;
        Assert::assertIsInt($index, 'the active signature is an index');

        return new self(
            count($signatures),
            Signature::fromWire($signatures[$index] ?? $signatures[0], $result->activeParameter ?? null),
        );
    }
}
