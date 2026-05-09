<?php

declare(strict_types=1);

use Fixtures\Domain\User;

class TopLevelUseTest
{
    public function testTopLevelUse(): void
    {
        // Use statement at top level (no namespace)
        new User(/*|top_level_use*/
    }
}
