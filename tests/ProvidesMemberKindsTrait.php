<?php

declare(strict_types=1);

namespace Firehed\PhpLsp\Tests;

use Firehed\PhpLsp\Domain\MemberKind;

trait ProvidesMemberKindsTrait
{
    /**
     * @return iterable<string, array{MemberKind}>
     */
    public static function allMemberKinds(): iterable
    {
        foreach (MemberKind::cases() as $kind) {
            yield $kind->name => [$kind];
        }
    }
}
