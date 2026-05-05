<?php

declare(strict_types=1);

namespace Firehed\PhpLsp\Resolution;

use Firehed\PhpLsp\Domain\ClassName;
use Firehed\PhpLsp\Domain\MethodInfo;
use Firehed\PhpLsp\Domain\ParameterInfo;
use Firehed\PhpLsp\Domain\Type;
use Firehed\PhpLsp\Domain\Visibility;
use Firehed\PhpLsp\Index\Location;
use Firehed\PhpLsp\Utility\DocblockParser;

/**
 * A resolved method wrapping MethodInfo.
 */
final readonly class ResolvedMethod implements ResolvedMember, ResolvedCallable
{
    public function __construct(
        private MethodInfo $info,
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
        return $this->info->returnType;
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
        return $this->info->isStatic;
    }

    public function getParameters(): array
    {
        return $this->info->parameters;
    }

    public function getReturnType(): ?Type
    {
        return $this->info->returnType;
    }

    public function getParameterAtPosition(int $position): ?ParameterInfo
    {
        foreach ($this->info->parameters as $param) {
            if ($param->position === $position) {
                return $param;
            }
        }
        return null;
    }

    public function getParameterByName(string $name): ?ParameterInfo
    {
        foreach ($this->info->parameters as $param) {
            if ($param->name === $name) {
                return $param;
            }
        }
        return null;
    }
}
