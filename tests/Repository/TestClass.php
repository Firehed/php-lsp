<?php

declare(strict_types=1);

namespace Firehed\PhpLsp\Tests\Repository;

class TestClass
{
    use TestTrait;

    public const TEST_CONST = 'value';

    public string $publicProp;

    public function publicMethod(): void
    {
    }
}
