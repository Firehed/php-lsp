<?php

declare(strict_types=1);

namespace Firehed\PhpLsp\Resolution\Position;

use PhpParser\Node\Attribute;
use PhpParser\Node\Expr\FuncCall;
use PhpParser\Node\Expr\MethodCall;
use PhpParser\Node\Expr\New_;
use PhpParser\Node\Expr\NullsafeMethodCall;
use PhpParser\Node\Expr\StaticCall;

/**
 * The call whose argument list holds a position, and where in it.
 */
final readonly class CallSite
{
    /**
     * @param list<string> $usedNames The names of the arguments written by name
     */
    public function __construct(
        public FuncCall|MethodCall|NullsafeMethodCall|StaticCall|New_|Attribute $call,
        public int $argumentIndex,
        public array $usedNames,
        public int $positionalCount,
    ) {
    }
}
