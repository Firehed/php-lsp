<?php

declare(strict_types=1);

namespace Firehed\PhpLsp\Resolution;

use Firehed\PhpLsp\Domain\ParameterInfo;
use Firehed\PhpLsp\Domain\ResolvedCallableInterface;
use Firehed\PhpLsp\Domain\ResolvedSymbolInterface;
use Firehed\PhpLsp\Parser\ParsedDocument;
use PhpParser\Node\Attribute;
use PhpParser\Node\Expr;
use PhpParser\Node\Expr\FuncCall;
use PhpParser\Node\Expr\MethodCall;
use PhpParser\Node\Expr\New_;
use PhpParser\Node\Expr\NullsafeMethodCall;
use PhpParser\Node\Expr\StaticCall;
use PhpParser\Node\Name;
use PhpParser\Node\Param;

/**
 * What an expression in a parsed document resolves to, its type included.
 */
interface ExpressionResolverInterface
{
    public function resolve(Expr $expr, ParsedDocument $parsed): ?ResolvedSymbolInterface;

    /**
     * What a call invokes: a function or method, or the constructor a `new`
     * or an attribute runs.
     */
    public function resolveCallable(
        FuncCall|MethodCall|NullsafeMethodCall|StaticCall|New_|Attribute $call,
        ParsedDocument $parsed,
    ): ?ResolvedCallableInterface;

    /**
     * What a member accessed on $receiver is looked up on, or null when the
     * receiver names no class-like. A class named as written resolves where it
     * is written.
     */
    public function memberTarget(Expr|Name $receiver, ParsedDocument $parsed): ?MemberTarget;

    /**
     * A parameter as its declaration declares it.
     */
    public function resolveParameter(Param $param, ParsedDocument $parsed): ParameterInfo;

    /**
     * A variable as a binding in a scope declares it.
     */
    public function resolveBinding(VariableBinding $binding, ParsedDocument $parsed): ResolvedVariable;
}
