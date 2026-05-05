<?php

declare(strict_types=1);

namespace Firehed\PhpLsp\Resolution;

use Firehed\PhpLsp\Domain\ClassName;
use Firehed\PhpLsp\Domain\EnumCaseInfo;
use Firehed\PhpLsp\Domain\Type;
use Firehed\PhpLsp\Index\Location;
use Firehed\PhpLsp\Utility\DocblockParser;

/**
 * A resolved enum case wrapping EnumCaseInfo.
 *
 * Note: Enum cases do not implement ResolvedMember because they don't have
 * visibility or static modifiers in PHP - they are implicitly public and
 * static-like by nature.
 */
final readonly class ResolvedEnumCase implements ResolvedSymbol
{
    public function __construct(
        private EnumCaseInfo $info,
    ) {
    }

    public function getDefinitionLocation(): ?Location
    {
        return Location::fromFileLine($this->info->file, $this->info->line);
    }

    public function getDocumentation(): ?string
    {
        if ($this->info->docblock === null) {
            return null;
        }
        return DocblockParser::extractDescription($this->info->docblock);
    }

    /**
     * Returns the declaring enum's ClassName as the type.
     */
    public function getType(): Type
    {
        return $this->info->declaringClass;
    }

    public function format(): string
    {
        return $this->info->format();
    }

    /**
     * Returns the class that declares this enum case.
     */
    public function getDeclaringClass(): ClassName
    {
        return $this->info->declaringClass;
    }
}
