<?php

declare(strict_types=1);

namespace Fixtures\IncompleteCode;

// Deliberately malformed; php-parser recovers with an Error node in the
// parameter's var slot. Loaded through the AST only, never autoloaded.

class BrokenPromotedProperty
{
    public function __construct(private $) {}
}

function brokenFreeStandingParam(: string) {}
