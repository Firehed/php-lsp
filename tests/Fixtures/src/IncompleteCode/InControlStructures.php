<?php

declare(strict_types=1);

namespace Fixtures\IncompleteCode;

use Fixtures\Domain\User;

/**
 * Fixture for testing LSP features with incomplete code inside various contexts.
 *
 * IMPORTANT: Each method should have only ONE incomplete expression to allow
 * parser error recovery to work. Multiple incomplete statements in the same
 * file causes the parser to give up entirely.
 */
class InControlStructures
{
    private string $name;
    private User $user;

    public function getName(): string
    {
        return $this->name;
    }

    public function setName(string $name): void
    {
        $this->name = $name;
    }

    public function getUser(): User
    {
        return $this->user;
    }
}

// Separate class for each test scenario to allow parser recovery
class IncompleteEmptyIf
{
    public function test(): void
    {
        if (/*|empty_if*/
    }
}

class IncompleteEmptyWhile
{
    public function test(): void
    {
        while (/*|empty_while*/
    }
}

class IncompleteEmptyFor
{
    public function test(): void
    {
        for ($i = 0; /*|empty_for*/
    }
}

class IncompleteEmptyForeach
{
    public function test(): void
    {
        foreach (/*|empty_foreach*/
    }
}

class IncompleteEmptySwitch
{
    public function test(): void
    {
        switch (/*|empty_switch*/
    }
}

class IncompleteEmptyMatch
{
    public function test(): void
    {
        match (/*|empty_match*/
    }
}

class IncompleteEmptyDoWhile
{
    public function test(): void
    {
        do {} while (/*|empty_do_while*/
    }
}

class IncompleteEmptyElseif
{
    public function test(): void
    {
        if (true) {
        } elseif (/*|empty_elseif*/
    }
}

class IncompletePartialIdentIf
{
    public function test(): void
    {
        if (str/*|partial_ident_if*/
    }
}

class IncompletePartialVarWhile
{
    public function test(): void
    {
        if ($thi/*|partial_var_while*/
    }
}

class IncompleteVarStartIf
{
    public function test(): void
    {
        if ($/*|var_start_if*/
    }
}

class IncompleteVarStartSwitch
{
    public function test(): void
    {
        switch ($/*|var_start_switch*/
    }
}

class IncompleteThisAccessIf
{
    private string $name;

    public function getName(): string
    {
        return $this->name;
    }

    public function test(): void
    {
        if ($this->/*|this_access_if*/
    }
}

class IncompleteVarAccessWhile
{
    public function helper(): void
    {
    }

    public function test(User $user): void
    {
        while ($user->/*|var_access_while*/
    }
}

class IncompleteNullsafeAccessFor
{
    public function test(): void
    {
        for ($i = 0; $this?->/*|nullsafe_access_for*/
    }
}

class IncompleteStaticAccessMatch
{
    public function test(): void
    {
        match (User::/*|static_access_match*/
    }
}

class IncompleteSelfAccessSwitch
{
    public const FOO = 1;

    public function test(): void
    {
        switch (self::/*|self_access_switch*/
    }
}

class IncompleteThisPrefixIf
{
    private string $name;

    public function getName(): string
    {
        return $this->name;
    }

    public function getUser(): User
    {
        return new User();
    }

    public function test(): void
    {
        if ($this->get/*|this_prefix_if*/
    }
}

class IncompleteInReturn
{
    private string $name;

    public function getName(): string
    {
        return $this->name;
    }

    public function test(): void
    {
        return $this->/*|in_return*/
    }
}

class IncompleteInAssignment
{
    private string $name;

    public function getName(): string
    {
        return $this->name;
    }

    public function test(): void
    {
        $x = $this->/*|in_assignment*/
    }
}

class IncompleteInArray
{
    private string $name;

    public function getName(): string
    {
        return $this->name;
    }

    public function test(): void
    {
        $arr = [$this->/*|in_array*/
    }
}

class IncompleteInCallArg
{
    private string $name;

    public function getName(): string
    {
        return $this->name;
    }

    public function setName(string $name): void
    {
        $this->name = $name;
    }

    public function test(): void
    {
        $this->setName($this->/*|in_call_arg*/
    }
}

class IncompleteInTernary
{
    private string $name;

    public function getName(): string
    {
        return $this->name;
    }

    public function test(): void
    {
        $x = $this->/*|in_ternary*/ ? 1 : 0
    }
}

// Signature help scenarios
class IncompleteSigMethodIf
{
    public function setName(string $name): void
    {
    }

    public function test(): void
    {
        if ($this->setName(/*|sig_method_if*/
    }
}

class IncompleteSigFunctionWhile
{
    public function test(): void
    {
        while (strlen(/*|sig_function_while*/
    }
}

class IncompleteSigStaticMatch
{
    public function test(): void
    {
        match (User::create(/*|sig_static_match*/
    }
}

class IncompleteSigNested
{
    public function getName(): string
    {
        return '';
    }

    public function setName(string $name): void
    {
    }

    public function test(): void
    {
        $this->setName($this->getName(/*|sig_nested*/
    }
}

class IncompleteSigReturn
{
    public function setName(string $name): void
    {
    }

    public function test(): void
    {
        return $this->setName(/*|sig_return*/
    }
}

// Hover/Definition - complete expressions in control structures
class CompleteHoverPropIf
{
    private string $name = '';

    public function test(): void
    {
        if ($this->name) {} //hover:hover_prop_if
    }
}

class CompleteHoverMethodWhile
{
    public function getName(): string
    {
        return '';
    }

    public function test(): void
    {
        while ($this->getName()) {} //hover:hover_method_while
    }
}

class CompleteHoverVarFor
{
    public function test(User $user): void
    {
        for ($i = 0; $user->getName(); $i++) {} //hover:hover_var_for
    }
}

class CompleteDefPropIf
{
    private string $name = '';

    public function test(): void
    {
        if ($this->name) {} //def:def_prop_if
    }
}

class CompleteDefMethodSwitch
{
    public function getName(): string
    {
        return '';
    }

    public function test(): void
    {
        switch ($this->getName()) {} //def:def_method_switch
    }
}
