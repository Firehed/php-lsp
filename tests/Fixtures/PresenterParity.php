<?php

declare(strict_types=1);

/**
 * Doubles the input number.
 *
 * @param int $value the input
 * @return int twice the input
 */
function presenterParityDouble(int $value): int
{
    return $value * 2;
}

$hoverCall = presenterParityDouble(1); //hover:presenter_hover
$sigCall = presenterParityDouble(/*|presenter_sig*/1);

class PresenterParityCompletionTrigger
{
    public function trigger(): void
    {
        $x = presenterParityDou/*|presenter_completion*/
    }
}
