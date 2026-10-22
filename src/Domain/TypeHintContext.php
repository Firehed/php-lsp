<?php

declare(strict_types=1);

namespace Firehed\PhpLsp\Domain;

enum TypeHintContext
{
    case Property;
    case Parameter;
    case ReturnType;
}
