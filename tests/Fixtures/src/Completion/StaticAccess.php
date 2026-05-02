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
    public string $instanceProp = 'instance';

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

    public function triggerSelfEmpty(): void
    {
        self::/*|self_empty*/
    }

    public function triggerSelfPrefix(): void
    {
        self::get/*|self_prefix*/
    }

    public function triggerSelfConstantPrefix(): void
    {
        self::NA/*|self_const_prefix*/
    }

    public function triggerStaticKeyword(): void
    {
        static::/*|static_keyword*/
    }

    public function getInstanceProp(): string
    {
        return $this->instanceProp;
    }

    public function triggerSelfChain(): void
    {
        self::getInstance()->/*|self_chain*/
    }

    public function triggerStaticChain(): void
    {
        static::create()->/*|static_chain*/
    }

    public function triggerSelfChainPrefix(): void
    {
        self::getInstance()->get/*|self_chain_prefix*/
    }
}
