<?php

declare(strict_types=1);

namespace Firehed\PhpLsp\Resolution;

use Firehed\PhpLsp\Domain\ClassName;
use Firehed\PhpLsp\Domain\Visibility;

/**
 * A resolved class member (method, property, constant).
 */
interface ResolvedMember extends ResolvedSymbol
{
    /**
     * Returns the class that declares this member.
     */
    public function getDeclaringClass(): ClassName;

    public function getVisibility(): Visibility;

    public function isStatic(): bool;
}
