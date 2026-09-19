<?php

declare(strict_types=1);

namespace Fixtures\Resolution;

/**
 * The target class name is written in lowercase, so the vantage and target
 * ClasslikeName instances that MemberAccessDetector::visibilityBetween sees
 * disagree on letter case even though they name the same class. PHP treats
 * them as one class; the visibility check must too.
 */
class CaseInsensitiveIdentity
{
    private const int SECRET = 42;

    public function m(): int
    {
        return \fixtures\resolution\caseinsensitiveidentity::SECRET/*|case_identity*/;
    }
}
