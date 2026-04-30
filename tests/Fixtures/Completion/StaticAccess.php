<?php

declare(strict_types=1);

namespace Fixtures\Completion;

class StaticAccess
{
    public const NAME = 'static';
    protected const INTERNAL = 'internal';
    private const SECRET = 'secret';

    public static string $instance;
    private static int $counter = 0;

    public static function create(): self
    {
        return new self();
    }

    public static function getInstance(): self
    {
        return new self();
    }

    private static function reset(): void
    {
        self::$counter = 0;
    }

    public function example(): void
    {
        self::/*|self_empty*/
        self::get/*|self_prefix*/
        static::/*|static_keyword*/
    }
}

class StaticCaller
{
    public function call(): void
    {
        StaticAccess::/*|external_static*/
        StaticAccess::NAME/*|const_access*/
    }
}
