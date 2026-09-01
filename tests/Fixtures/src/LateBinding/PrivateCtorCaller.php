<?php

declare(strict_types=1);

namespace Fixtures\LateBinding;

final class PrivateCtorCaller
{
    public function build(): PrivateCtor
    {
        return new PrivateCtor(/*|sig_new_private_ctor*/'value');
    }
}
