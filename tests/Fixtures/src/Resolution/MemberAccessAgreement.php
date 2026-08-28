<?php

declare(strict_types=1);

namespace Fixtures\Resolution;

use Fixtures\Domain\User;
use Fixtures\Inheritance\ChildClass;

/**
 * Fixture for MemberAccessDetector agreement between AST and text paths.
 *
 * Each marker sits between an identifier and its trailing punctuation
 * (opening paren for calls, semicolon for property reads) so the file
 * still parses AND the cursor lands where both paths must resolve.
 */
class MemberAccessAgreement extends ChildClass
{
    public function thisMethod(): void
    {
        $this->parentMethod/*|this_method*/();
    }

    public function thisProperty(): void
    {
        echo $this->parentProperty/*|this_property*/;
    }

    public function selfStatic(): void
    {
        self::childMethod/*|self_static*/();
    }

    public function parentStatic(): void
    {
        parent::parentMethod/*|parent_static*/();
    }

    public function classStatic(): void
    {
        User::create/*|class_static*/('id', 'name', 'email');
    }

    public function fqStatic(): void
    {
        \Fixtures\Domain\User::create/*|fq_static*/('id', 'name', 'email');
    }
}
