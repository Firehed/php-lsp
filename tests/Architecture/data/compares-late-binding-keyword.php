<?php

declare(strict_types=1);

namespace Firehed\PhpLsp\Tests\Architecture\Data;

/**
 * Every syntactic form of a comparison against `self`, `static`, or `parent`
 * that a class-name-resolution site would use — the canary that pins the
 * {@see \Firehed\PhpLsp\Tests\Architecture\LateBindingKeywordConfinementTest}
 * scanner. Adding a form here means adding the same form to the visitor, so
 * the two cannot drift apart.
 */
final class ComparesLateBindingKeyword
{
    public function identical(string $n): bool
    {
        return $n === 'self';
    }

    public function equalReversed(string $n): bool
    {
        return 'static' == $n;
    }

    public function notIdentical(string $n): bool
    {
        return $n !== 'parent';
    }

    public function notEqualCaseFolded(string $n): bool
    {
        return 'SELF' != $n;
    }

    public function matchOnKeyword(string $n): int
    {
        return match ($n) {
            'self' => 1,
            'static', 'parent' => 2,
            default => 0,
        };
    }

    public function switchOnKeyword(string $n): int
    {
        switch ($n) {
            case 'self':
                return 1;
            case 'parent':
                return 2;
            default:
                return 0;
        }
    }
}
