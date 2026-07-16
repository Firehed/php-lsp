<?php

declare(strict_types=1);

namespace App;

class CatalogProbe
{
    public function make(): void
    {
        new \Fixtures\Domain\U/*|ondisk_class*/
    }

    public function iface(): void
    {
        new \Psr\Http\Message\R/*|ondisk_interface*/
    }
}
