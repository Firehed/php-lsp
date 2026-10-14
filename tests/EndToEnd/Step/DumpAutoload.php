<?php

declare(strict_types=1);

namespace Firehed\PhpLsp\Tests\EndToEnd\Step;

use Firehed\PhpLsp\Tests\EndToEnd\Session\Session;

/**
 * Runs `composer dump-autoload` in the project, as a `composer install` after
 * a checkout would, and the client's file watcher reports what it rewrote.
 */
final readonly class DumpAutoload implements StepInterface
{
    public function run(Session $session): void
    {
        $session->dumpAutoload();
    }
}
