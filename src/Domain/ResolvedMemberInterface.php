<?php

declare(strict_types=1);

namespace Firehed\PhpLsp\Domain;

/**
 * A resolved class member (method, property, constant, enum case).
 */
interface ResolvedMemberInterface extends ResolvedSymbolInterface
{
    /**
     * Returns the class that declares this member.
     */
    public function getDeclaringClass(): ClassName;

    public function getMemberKind(): MemberKind;

    public function getName(): MethodName|PropertyName|ConstantName|EnumCaseName;

    public function getVisibility(): Visibility;

    public function isStatic(): bool;
}
