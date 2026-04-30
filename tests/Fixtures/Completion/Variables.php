<?php

declare(strict_types=1);

namespace Fixtures\Completion;

class Variables
{
    public function withParameters(string $name, int $age): void
    {
        $n/*|param_prefix*/
    }

    public function withLocalVariable(): void
    {
        $logger = new self();
        $l/*|local_prefix*/
    }

    public function triggerThisPrefix(): void
    {
        $t/*|this_prefix*/
    }

    public function withForeach(): void
    {
        foreach ([1, 2] as $item) {
            $i/*|foreach_prefix*/
        }
    }

    public function scopeIsolation(): void
    {
        $siteDir = '/var/www';
        $s/*|scope_var_prefix*/
    }

    public function showParameterType(string $typedParam): void
    {
        $/*|param_type_detail*/
    }
}

class NamespacedVariables
{
    public function getName(): void
    {
        $t/*|namespaced_this_prefix*/
    }
}

class ClosureVariables
{
    public function withClosure(): void
    {
        $fn = function ($param) {
            $localVar = 1;
            $l/*|closure_local*/
        };
    }
}
