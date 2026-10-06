<?php

declare(strict_types=1);

// A file-scope $this binds to the class declared above it, not the one below.
class DeclaredAbove
{
}

$this;

class DeclaredBelow
{
}
