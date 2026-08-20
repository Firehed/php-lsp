<?php

declare(strict_types=1);

namespace Firehed\PhpLsp\Tests\Architecture\Data;

use Firehed\PhpLsp\Domain\NameKind;

/**
 * A consumer deciding behavior by match on a symbol-kind enum,
 * which RFC 1 §4.5 forbids.
 */
final class MatchOnKindEnum
{
    public function branchOnKind(NameKind $kind): string
    {
        return match ($kind) {
            NameKind::ClassLike => 'class',
            NameKind::Function => 'function',
            NameKind::Constant => 'constant',
        };
    }
}
