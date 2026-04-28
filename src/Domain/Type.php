<?php

declare(strict_types=1);

namespace Firehed\PhpLsp\Domain;

interface Type extends Formattable
{
    /**
     * @return list<ClassName>
     */
    public function getResolvableClassNames(): array;

    public function isNullable(): bool;
}
