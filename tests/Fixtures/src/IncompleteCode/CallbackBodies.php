<?php

declare(strict_types=1);

namespace Fixtures\IncompleteCode;

class CallbackBodies
{
    public function closureBody(array $items): void
    {
        array_map(function ($item) { return /*|closure_body*/
    }

    public function closureParameter(array $items): void
    {
        array_map(function (/*|closure_parameter*/
    }

    public function arrowParameter(array $items): void
    {
        array_map(fn (/*|arrow_parameter*/
    }

    public function arrowBody(array $items): void
    {
        array_map(fn ($item) => /*|arrow_body*/
    }

    public function arrowBodyMember(array $items): void
    {
        array_map(static fn (User $item): string => $item->/*|arrow_body_member*/
    }

    public function callInArrowBody(array $items): void
    {
        array_map(fn ($item) => strlen(/*|call_in_arrow_body*/
    }

    public function afterArrowArgument(array $items): void
    {
        array_map(fn ($item) => $item, /*|after_arrow_argument*/
    }

    public function anonymousClassBody(): void
    {
        new class { public function value() { return /*|anonymous_class_body*/
    }
}
