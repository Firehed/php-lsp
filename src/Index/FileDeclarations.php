<?php

declare(strict_types=1);

namespace Firehed\PhpLsp\Index;

use PhpParser\Node;
use PhpParser\Node\Expr;
use PhpParser\Node\Stmt;

/**
 * The names one file declares, across all three of PHP's symbol namespaces.
 *
 * An `autoload.files` entry has no name -> file map of any kind, so the only way to
 * learn what it declares is to parse it — and once parsed, every kind costs the same
 * walk. Narrowing this to functions and constants would leave a class-like declared
 * there reachable at runtime but invisible here, for no saving (Plan 0002 §3).
 */
final readonly class FileDeclarations
{
    /**
     * @param list<Declaration<Stmt\ClassLike>> $classLikes
     * @param list<Declaration<Stmt\Function_>> $functions
     * @param list<Declaration<Node\Const_|Expr\FuncCall>> $constants a `const`
     *        declarator, or the `define()` call that names the constant
     */
    public function __construct(
        public array $classLikes,
        public array $functions,
        public array $constants,
    ) {
    }
}
