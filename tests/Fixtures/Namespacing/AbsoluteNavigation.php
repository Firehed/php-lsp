<?php

declare(strict_types=1);

namespace App;

/**
 * Absolute (`\`-rooted) navigation in class positions other than `new`. Each
 * incomplete reference lives in its own method so parser error recovery is not
 * confused by multiple broken statements (#330).
 */
class AbsoluteNavigation
{
    public function inCatch(): void
    {
        try {
            $this->inCatch();
        } catch (\Ps/*|catch_nav*/) {
        }
    }

    public function withParam(\Ps/*|param_nav*/): void
    {
    }

    public function withReturn(): \Ps/*|return_nav*/
    {
    }
}
