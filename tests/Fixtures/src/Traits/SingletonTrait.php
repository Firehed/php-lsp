<?php

declare(strict_types=1);

namespace Fixtures\Traits;

trait SingletonTrait
{
    private static ?self $instance = null;

    public static function instance(): static
    {
        if (self::$instance === null) {
            self::$instance = new static();
        }
        return self::$instance;
    }

    public static function getInstance(): self
    {
        return static::instance();
    }

    public static function tryInstance(): ?static
    {
        return self::$instance;
    }
}
