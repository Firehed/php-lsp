<?php

declare(strict_types=1);

namespace Fixtures\Resolution;

use Fixtures\Inheritance\ParentClass;

final class MemberAccessReceivers extends ParentClass
{
    public function receivers(): void
    {
        parent/*|parent*/::parentMethod();
        self/*|self*/::receivers();
        ParentClass/*|named*/::PARENT_CONST;
        Undeclared/*|undeclared*/::NOTHING;
        $this/*|instance*/->receivers();
        $this/*|expression_before_double_colon*/::PARENT_CONST;
        $unknown/*|unknown*/->receivers();
        $text = fn (string $text) => $text/*|no_class*/->length;
    }
}
