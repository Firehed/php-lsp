<?php

declare(strict_types=1);

namespace Fixtures\Attributes;

/**
 * A plain class that carries a class-level attribute but is not itself declared
 * `#[Attribute]`. It must not be treated as an attribute class.
 */
#[NoConstructorAttribute]
final class UsesAttribute
{
}
