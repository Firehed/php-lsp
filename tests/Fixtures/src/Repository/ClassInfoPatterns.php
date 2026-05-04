<?php

declare(strict_types=1);

namespace Fixtures\Repository;

use Fixtures\Traits\HasTimestamps;

trait ExampleTrait
{
}

class ClassInfoPatterns
{
    use ExampleTrait;

    public const string PUBLIC_CONST = 'value';
    protected const PROTECTED_CONST = 123;
    private const PRIVATE_CONST = true;

    public string $publicProp;
    protected int $protectedProp;
    private static bool $privateStaticProp;
    public readonly string $readonlyProp;

    public function __construct(
        public string $name,
        private readonly int $id,
    ) {
        $this->publicProp = '';
        $this->protectedProp = 0;
        $this->readonlyProp = 'immutable';
    }

    public function publicMethod(): void
    {
    }

    protected function protectedMethod(): string
    {
        return '';
    }

    private static function privateStaticMethod(): int
    {
        return 0;
    }

    public function withParams(string $name, int $count = 0, string ...$items): void
    {
    }

    public static function createSelf(): ?self
    {
        return new self('', 0);
    }

    public function buildStatic(): static
    {
        return $this;
    }
}
