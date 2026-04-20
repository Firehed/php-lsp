<?php

declare(strict_types=1);

namespace Firehed\PhpLsp\Tests\Utility;

class TestFixtureClass
{
    public string $publicProperty = '';
    protected string $protectedProperty = '';
    private string $privateProperty = '';

    public function publicMethod(): void
    {
    }

    protected function protectedMethod(): void
    {
    }

    private function privateMethod(): void
    {
    }
}
