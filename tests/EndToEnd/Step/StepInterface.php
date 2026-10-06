<?php

declare(strict_types=1);

namespace Firehed\PhpLsp\Tests\EndToEnd\Step;

use Firehed\PhpLsp\Tests\EndToEnd\Session\Session;

/**
 * One thing a person does in an editor.
 */
interface StepInterface
{
    public function run(Session $session): void;
}
