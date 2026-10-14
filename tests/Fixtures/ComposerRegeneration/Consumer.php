<?php

declare(strict_types=1);

/**
 * Names `Regen\` classes, which resolve only once a script has put the
 * regenerated maps in place.
 */
final class RegenerationConsumer
{
    public function make(): void
    {
        new \Regen\Widget(); //hover:regen_widget
    }

    public function complete(): void
    {
        new \Regen\W/*|regen_prefix*/
    }
}
