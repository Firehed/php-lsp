<?php

declare(strict_types=1);

namespace Fixtures\ThisBinding;

// Where PHP binds `$this` (PHP manual, Anonymous functions: Automatic binding
// of $this, and Static anonymous functions). Each `$in…` variable marks one.
class ThisBinding
{
    public function instanceMethod(): void
    {
        $inInstanceMethod = 1;
        $closure = function () {
            $inClosure = 1;
        };
        $arrow = fn () => $inArrow = 1;
        $staticClosure = static function () {
            $inStaticClosure = 1;
        };
        $staticArrow = static fn () => $inStaticArrow = 1;
    }

    public static function staticMethod(\DateTime $param): void
    {
        $inStaticMethod = $this; //jtd:static_method_this this
        $param->format('c'); //jtd:static_method_param param
        $closure = function () {
            $inClosureInStaticMethod = 1;
        };
    }

    public int $count = 0;

    // A property hook runs on the object (PHP manual, Property hooks).
    public int $doubled {
        get {
            $inPropertyHook = $this->count;
            return $inPropertyHook * 2;
        }
        set(int $value) {
            $this->count = $value; //jtd:hook_parameter value
        }
    }

    public int $tripled {
        get => $this->count * 3;
    }
}

function freeFunction(): void
{
    $closure = function () {
        $inClosureInFunction = 1;
    };
}
