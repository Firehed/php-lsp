<?php

declare(strict_types=1);

namespace Fixtures\Attributes;

use Attribute;

#[Attribute(Attribute::TARGET_ALL)]
final class NoConstructorAttribute
{
}
