<?php

declare(strict_types=1);

namespace Fixtures\Resolution;

/**
 * Fixture for ExpressionResolver constant resolution (build-manifest step-28).
 *
 * PHP name-resolution rules 5-7 apply to unqualified constants: the namespaced
 * candidate is tried first, then the global one.
 */
const NAMESPACED_CONSTANT = 1;

function useNamespacedConstant(): int
{
    return NAMESPACED_CONSTANT; //hover:namespaced_const_fetch
}

function useGlobalConstant(): int
{
    return FIXTURE_HELPER_DEFINED; //hover:global_const_fetch
}
