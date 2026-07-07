<?php

class StaticNamed
{
    public function staticFactory(): void
    {
    }

    public function normalMethod(): void
    {
    }

    public string $staticCache = '';

    public static function realStatic(): void
    {
    }
}
