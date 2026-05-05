<?php

declare(strict_types=1);

namespace Firehed\PhpLsp\Resolution;

use Firehed\PhpLsp\Domain\ClassName;
use Firehed\PhpLsp\Domain\ConstantInfo;
use Firehed\PhpLsp\Domain\Type;
use Firehed\PhpLsp\Domain\Visibility;
use Firehed\PhpLsp\Index\Location;
use Firehed\PhpLsp\Utility\DocblockParser;

/**
 * A resolved class constant wrapping ConstantInfo.
 */
final readonly class ResolvedConstant implements ResolvedMember
{
    public function __construct(
        private ConstantInfo $info,
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

    public function getType(): ?Type
    {
        return $this->info->type;
    }

    public function format(): string
    {
        return $this->info->format();
    }

    public function getDeclaringClass(): ClassName
    {
        return $this->info->declaringClass;
    }

    public function getVisibility(): Visibility
    {
        return $this->info->visibility;
    }

    public function isStatic(): bool
    {
        return true;
    }
}
