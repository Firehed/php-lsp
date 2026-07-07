<?php
namespace App;

class MultilineParams
{
    public function longSignature(
        string $first,
        int $second,
        SomeClass $typed
    ): void {
        $typed->/*|multiline_param*/
    }
}
