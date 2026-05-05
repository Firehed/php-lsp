<?php

declare(strict_types=1);

namespace Firehed\PhpLsp\Resolution;

use Firehed\PhpLsp\Domain\FunctionInfo;
use Firehed\PhpLsp\Domain\ParameterInfo;
use Firehed\PhpLsp\Domain\Type;
use Firehed\PhpLsp\Index\Location;
use Firehed\PhpLsp\Utility\DocblockParser;

/**
 * A resolved function wrapping FunctionInfo.
 */
final readonly class ResolvedFunction implements ResolvedCallable
{
    public function __construct(
        private FunctionInfo $info,
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
