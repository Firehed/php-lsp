<?php

declare(strict_types=1);

namespace Fixtures\TypeInference;

use DateTime;
use DateTimeImmutable;
use Exception;
use Throwable;

class BuiltinTypes
{
    public function newDateTime(): DateTime
    {
        $x = new DateTime();
        return $x;
    }

    public function parameterType(DateTime $dt): void
    {
        $x = $dt;
    }

    public function assignmentFromNew(): DateTime
    {
        $x = new DateTime();
        $y = $x;
        return $y;
    }

    public function methodCallOnBuiltin(): string
    {
        $dt = new DateTime();
        $result = $dt->format('Y');
        return $result;
    }

    public function exceptionGetMessage(): string
    {
        $ex = new Exception();
        $result = $ex->getMessage();
        return $result;
    }

    public function exceptionNonExistentMethod(): void
    {
        $ex = new Exception();
        $result = $ex->nonExistentMethod();
    }

    public function exceptionGetLine(): int
    {
        $ex = new Exception();
        $result = $ex->getLine();
        return $result;
    }

    public function cloneExpression(DateTime $dt): DateTime
    {
        $cloned = clone $dt;
        return $cloned;
    }

    public function ternaryExpression(DateTime $dt, bool $cond): ?DateTime
    {
        $result = $cond ? $dt : null;
        return $result;
    }

    public function nullCoalesceExpression(?DateTime $dt): DateTime|DateTimeImmutable|null
    {
        $result = $dt ?? new DateTimeImmutable();
        return $result;
    }

    public function nullableParameterType(?DateTime $dt): void
    {
        $x = $dt;
    }

    public function unionParameterType(DateTime|DateTimeImmutable $dt): void
    {
        $x = $dt;
    }

    public function closureParameter(): void
    {
        $fn = function (DateTime $dt) {
            $x = $dt;
        };
    }

    public function arrowFunctionParameter(): void
    {
        $fn = fn(DateTime $dt) => $dt->format('Y');
    }

    public function nullableMethodReturnType(): ?Throwable
    {
        $ex = new Exception();
        $prev = $ex->getPrevious();
        return $prev;
    }

    public function chainWithNullableIntermediate(): string
    {
        $ex = new Exception();
        $msg = $ex->getPrevious()->getMessage();
        return $msg;
    }

    public function nullsafeMethodCall(?Exception $ex): ?string
    {
        $msg = $ex?->getMessage();
        return $msg;
    }

    public function nullsafePropertyFetch(?Exception $ex): void
    {
        $msg = $ex?->message; //hover:builtin_class_property
    }

    public function nullsafeMethodCallChain(?Exception $ex): ?Throwable
    {
        $prev = $ex?->getPrevious();
        return $prev;
    }

    public function builtinFunctionCall(): int
    {
        $len = strlen('hello');
        return $len;
    }

    public function unknownVariableCall(): void
    {
        $x = unknown_function();
    }

    public function dynamicFunctionCall(): void
    {
        $func = 'strlen';
        $result = $func('hello');
    }

    public function thisReference(): self
    {
        $x = $this;
        return $x;
    }
}
