<?php

declare(strict_types=1);

namespace Firehed\PhpLsp\Parser\Recovery;

use Firehed\PhpLsp\Parser\SyntaxSource\ParseAttempt;

/**
 * Recovers structure the parser could not read from code being edited.
 * Returns the attempt improved where it applies, or unchanged where it does
 * not; it only adds to the tree, never replaces what the parser read.
 */
interface RecoveryInterface
{
    public function recover(string $code, ParseAttempt $attempt): ParseAttempt;
}
