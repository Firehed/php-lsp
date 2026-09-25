<?php

declare(strict_types=1);

// The file's namespace deliberately does not match its PSR-4 candidate: under
// prefix `Trap\` pointed at this directory, the walker mints `Trap\RealTrap`
// from the filename, but Composer's own `findFile` does not verify what a file
// actually declares — so the catalog picks up an FQN no file declares. A
// downstream lookup on that FQN falls through every real backend, and if the
// last backend probes with `class_exists($fqn)` autoload-on, Composer's
// autoloader ends up executing this file's top-level code.
namespace Elsewhere;

class RealTrap
{
}
