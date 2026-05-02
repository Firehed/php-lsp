<?php

declare(strict_types=1);

namespace Firehed\PhpLsp\Domain;

enum LateBindingKeyword: string
{
    case Self = 'self';
    case Static = 'static';
    case Parent = 'parent';
}
