<?php

declare(strict_types=1);

namespace Fixtures\Position;

class HaltCompiler
{
    public function charge(): void
    {
    }
}

/*|before_halt*/

__halt_compiler();/*|halt_offset*/

Notes, read as data:
    $payment = new HaltCompiler();
    $payment->/*|data_member*/
    $payment->charge(/*|data_call*/
    HaltCo/*|data_word*/
