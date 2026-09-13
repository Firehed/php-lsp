<?php

declare(strict_types=1);

namespace Firehed\PhpLsp\Domain;

interface FormattableInterface
{
    public function format(): string;
}
