<?php

declare(strict_types=1);

namespace Fixtures\Completion;

class PrefixInsideName
{
    public static string $navigation = '';
    public string $label = '';

    public function trigger(): void
    {
        /*|before_static_property*/self::$navigation;
        /*|before_instance_property*/$this->label;
    }
}
