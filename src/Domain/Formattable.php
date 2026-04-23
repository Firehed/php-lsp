<?php

declare(strict_types=1);

namespace Firehed\PhpLsp\Domain;

interface Formattable
{
    public function format(): string;
}
