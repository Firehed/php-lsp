<?php

declare(strict_types=1);

namespace Fixtures\Resolution;

class CallbackBodies
{
    public function closureBody(array $items): array
    {
        return array_map(function ($item) {
            return /*|closure_body*/$item;
        }, $items);
    }

    public function closureParameter(array $items): array
    {
        return array_map(function (/*|closure_parameter*/$item) {
            return $item;
        }, $items);
    }

    public function arrowBody(array $items): array
    {
        return array_map(fn ($item) => /*|arrow_body*/$item, $items);
    }

    public function callInArrowBody(array $items): array
    {
        return array_map(fn ($item) => strlen(/*|call_in_arrow_body*/$item), $items);
    }

    public function anonymousClassBody(): object
    {
        return new class {
            public function value(): int
            {
                return /*|anonymous_class_body*/1;
            }
        };
    }

    public function anonymousClassArgument(): object
    {
        return new class (/*|anonymous_class_argument*/1) {
            public function __construct(public int $value)
            {
            }
        };
    }
}
