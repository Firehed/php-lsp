<?php

declare(strict_types=1);

// Variables local to nested function/class scopes must NOT leak into the
// file-level scope's variable collection.

function helper(): void
{
    $localToFunction = 1;
}

class Widget
{
    public function render(): void
    {
        $localToMethod = 2;
    }
}

$globalOne = 1;
$globalTwo = 2;
/*|nested_marker*/
