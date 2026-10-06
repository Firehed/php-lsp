<?php

declare(strict_types=1);

namespace Fixtures\DiskChange;

use function Fixtures\Helpers\helperAdded;

/**
 * Uses names that only exist once an end-to-end script has changed files on
 * disk.
 */
class Consumer
{
    public function useWidget(Widget $widget): void
    {
        $widget->greet(); //hover:widget_greet
    }

    public function useGadget(Gadget $gadget): void
    {
        $gadget->spin(); //hover:gadget_spin
    }

    public function makeWidget(): void
    {
        new Widget(); //hover:new_widget
    }

    public function makeLate(): void
    {
        new Late(); //hover:new_late
    }

    public function useHelper(): void
    {
        helperAdded(); //hover:helper_added
    }
}
