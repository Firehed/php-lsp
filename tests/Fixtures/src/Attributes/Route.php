<?php

declare(strict_types=1);

namespace Fixtures\Attributes;

use Attribute;

/**
 * Defines a route for a controller method.
 */
#[Attribute(Attribute::TARGET_METHOD)]
final class Route
{
    public function __construct(
        public readonly string $path,
        public readonly string $method = 'GET',
    ) {
    }
}
