<?php

declare(strict_types=1);

namespace Fixtures\Definition;

class VariableBindings
{
    public function assignmentBinding(): void
    {
        $x = 1;
        $x; //jtd:assignment_usage x
    }

    public function paramBinding(string $p): void
    {
        $p; //jtd:param_usage p
    }

    public function foreachValueBinding(): void
    {
        foreach ([1, 2] as $v) {
            $v; //jtd:foreach_value_usage v
        }
    }

    public function foreachKeyBinding(): void
    {
        foreach (['a' => 1] as $k => $v) {
            $k; //jtd:foreach_key_usage k
        }
    }

    public function catchBinding(): void
    {
        try {
            $ok = 1;
        } catch (\Throwable $e) {
            $e; //jtd:catch_usage e
        }
    }

    public function stepBackChaining(): void
    {
        $x = 1;
        $x = 2;
        $x; //jtd:second_x x
    }

    public function paramShadowsOuter(string $shadowed): void
    {
        $shadowed; //jtd:shadowed_usage shadowed
    }

    public function longClosureUseIsBinding(): void
    {
        $captured = 1;
        $fn = function () use ($captured) {
            $captured; //jtd:use_clause_usage captured
        };
    }

    public function longClosureIsolatesUncaptured(): void
    {
        $outer = 1;
        $fn = function () {
            $outer; //jtd:closure_uncaptured outer
        };
    }

    public function arrowFunctionFallsThrough(): void
    {
        $outer = 1;
        $fn = fn () => $outer; //jtd:arrow_fallthrough outer
    }

    public function thisReturnsNoBinding(): void
    {
        $this; //jtd:this_usage this
    }
}
