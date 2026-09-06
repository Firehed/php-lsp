<?php

declare(strict_types=1);

namespace Firehed\PhpLsp\Resolution;

use PhpParser\Node\Expr\Variable;

/**
 * A leftover home for one helper the call-context text path still calls into.
 * The parent-chain walk and the text fallback that used to live here both went
 * with build-manifest step-40, which moved the enclosing-class lookup for a
 * cursor node onto {@see Scope::atOffset}. Step-41 moves the last caller of
 * {@see self::seedThisPosition()} into the cursor-text source, and step-42
 * deletes this class outright.
 *
 * @internal
 */
final class EnclosingClassResolver
{
    /**
     * Stamp a synthetic `Variable('this')` with the position it would have
     * carried if the parser had produced it, so a downstream reader that keys
     * off the node's position finds the enclosing class-like.
     */
    public static function seedThisPosition(Variable $variable, int $line, int $offset): void
    {
        $variable->setAttribute('startLine', $line + 1);
        $variable->setAttribute('startFilePos', $offset);
    }
}
