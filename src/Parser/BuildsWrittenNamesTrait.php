<?php

declare(strict_types=1);

namespace Firehed\PhpLsp\Parser;

use PhpParser\Node\Name;

/**
 * Builds a name node from a name as written in source, in the form php-parser
 * gives it, so name resolution treats it the same whichever source read it.
 */
trait BuildsWrittenNamesTrait
{
    /**
     * @param array{startFilePos: int, endFilePos: int, startLine: int, endLine?: int} $attributes
     */
    private static function nameAsWritten(string $written, array $attributes): Name
    {
        if (str_starts_with($written, '\\')) {
            return new Name\FullyQualified(substr($written, 1), $attributes);
        }
        if (str_starts_with($written, 'namespace\\')) {
            return new Name\Relative(substr($written, strlen('namespace\\')), $attributes);
        }

        return new Name($written, $attributes);
    }
}
