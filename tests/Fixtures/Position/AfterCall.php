<?php

declare(strict_types=1);

strlen('x')/*|after_closed_call*/;
$made = new class (name/*|anonymous_argument*/: 1) {
    public function __construct(public int $name)
    {
    }
};
