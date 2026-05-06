<?php

declare(strict_types=1);

namespace Firehed\PhpLsp\Resolution;

use Firehed\PhpLsp\Domain\ClassName;
use Firehed\PhpLsp\Domain\ConstantName;
use Firehed\PhpLsp\Domain\EnumCaseName;
use Firehed\PhpLsp\Domain\MethodName;
use Firehed\PhpLsp\Domain\PropertyName;
use Firehed\PhpLsp\Domain\Visibility;

/**
 * A resolved class member (method, property, constant, enum case).
 */
interface ResolvedMember extends ResolvedSymbol
{
    /**
     * Returns the class that declares this member.
     */
    public function getDeclaringClass(): ClassName;

    public function getName(): MethodName|PropertyName|ConstantName|EnumCaseName;

    public function getVisibility(): Visibility;

    public function isStatic(): bool;
}
