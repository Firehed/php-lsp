<?php

declare(strict_types=1);

namespace Fixtures\Inheritance;

class Grandparent
{
    public const GRANDPARENT_CONST = 'grandparent';

    /** Grandparent property. */
    public string $grandparentProperty = 'grandparent';

    /** Grandparent method documentation. */
    public function grandparentMethod(): void
    {
    }

    /** Protected grandparent method. */
    protected function protectedGrandparentMethod(): void
    {
    }

    /** Grandparent static public method. */
    public static function grandparentStaticPublic(): void
    {
    }

    /** Grandparent static protected method. */
    protected static function grandparentStaticProtected(): void
    {
    }
}
