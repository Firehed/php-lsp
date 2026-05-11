<?php

declare(strict_types=1);

namespace Fixtures\IncompleteCode;

use Fixtures\Domain\User;

/**
 * Fixture for testing LSP features with incomplete code inside various contexts.
 *
 * The parser's error recovery may drop or misrepresent incomplete expressions
 * when they're nested inside control structures, calls, or other constructs.
 * These fixtures verify text-based fallback detection works across contexts.
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

    // =========================================================================
    // COMPLETION: Empty expression in various control structures
    // Each structure should offer variables, functions, classes, keywords
    // =========================================================================

    public function emptyIf(): void
    {
        if (/*|empty_if*/
    }

    public function emptyWhile(): void
    {
        while (/*|empty_while*/
    }

    public function emptyFor(): void
    {
        for ($i = 0; /*|empty_for*/
    }

    public function emptyForeach(): void
    {
        foreach (/*|empty_foreach*/
    }

    public function emptySwitch(): void
    {
        switch (/*|empty_switch*/
    }

    public function emptyMatch(): void
    {
        match (/*|empty_match*/
    }

    public function emptyDoWhile(): void
    {
        do {} while (/*|empty_do_while*/
    }

    public function emptyElseif(): void
    {
        if (true) {
        } elseif (/*|empty_elseif*/
    }

    // =========================================================================
    // COMPLETION: Partial identifiers in conditions
    // Testing that prefix filtering works
    // =========================================================================

    public function partialIdentifierInIf(): void
    {
        if (str/*|partial_ident_if*/
    }

    public function partialVariableInWhile(): void
    {
        if ($thi/*|partial_var_while*/
    }

    // =========================================================================
    // COMPLETION: Variable start ($) in conditions
    // =========================================================================

    public function variableStartInIf(): void
    {
        if ($/*|var_start_if*/
    }

    public function variableStartInSwitch(): void
    {
        switch ($/*|var_start_switch*/
    }

    // =========================================================================
    // COMPLETION: Member access in conditions
    // One representative case per access type
    // =========================================================================

    public function thisAccessInIf(): void
    {
        if ($this->/*|this_access_if*/
    }

    public function varAccessInWhile(User $user): void
    {
        while ($user->/*|var_access_while*/
    }

    public function nullsafeAccessInFor(): void
    {
        for ($i = 0; $this?->/*|nullsafe_access_for*/
    }

    public function staticAccessInMatch(): void
    {
        match (User::/*|static_access_match*/
    }

    public function selfAccessInSwitch(): void
    {
        switch (self::/*|self_access_switch*/
    }

    // =========================================================================
    // COMPLETION: Member access with prefix
    // =========================================================================

    public function thisAccessWithPrefixInIf(): void
    {
        if ($this->get/*|this_prefix_if*/
    }

    // =========================================================================
    // COMPLETION: Non-control-structure contexts
    // =========================================================================

    public function inReturn(): void
    {
        return $this->/*|in_return*/
    }

    public function inAssignment(): void
    {
        $x = $this->/*|in_assignment*/
    }

    public function inArrayLiteral(): void
    {
        $arr = [$this->/*|in_array*/
    }

    public function inCallArg(): void
    {
        $this->setName($this->/*|in_call_arg*/
    }

    public function inTernaryCondition(): void
    {
        $x = $this->/*|in_ternary*/ ? 1 : 0
    }

    // =========================================================================
    // SIGNATURE HELP: Method/function calls in structures
    // =========================================================================

    public function sigHelpMethodInIf(): void
    {
        if ($this->setName(/*|sig_method_if*/
    }

    public function sigHelpFunctionInWhile(): void
    {
        while (strlen(/*|sig_function_while*/
    }

    public function sigHelpStaticInMatch(): void
    {
        match (User::create(/*|sig_static_match*/
    }

    public function sigHelpNestedCalls(): void
    {
        $this->setName($this->getName(/*|sig_nested*/
    }

    public function sigHelpInReturn(): void
    {
        return $this->setName(/*|sig_return*/
    }

    // =========================================================================
    // HOVER: Complete expressions in control structures
    // =========================================================================

    public function hoverPropertyInIf(): void
    {
        if ($this->name) {} //hover:hover_prop_if
    }

    public function hoverMethodInWhile(): void
    {
        while ($this->getName()) {} //hover:hover_method_while
    }

    public function hoverVarMethodInFor(User $user): void
    {
        for ($i = 0; $user->getName(); $i++) {} //hover:hover_var_for
    }

    // =========================================================================
    // DEFINITION: Complete expressions in control structures
    // =========================================================================

    public function defPropertyInIf(): void
    {
        if ($this->name) {} //def:def_prop_if
    }

    public function defMethodInSwitch(): void
    {
        switch ($this->getName()) {} //def:def_method_switch
    }
}
