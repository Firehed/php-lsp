<?php

declare(strict_types=1);

namespace Fixtures\Utility;

class BranchingBindingSites
{
    public function method(): void
    {
        try {
            $inTry = 1;
        } finally {
            $inFinally = 2;
        }

        if (true) {
            $inIf = 3;
        } elseif (false) {
            $inElseIf = 4;
        }

        switch (5) {
            case 1:
                $inCase = 6;
                break;
        }
    }
}
