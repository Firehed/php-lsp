<?php

declare(strict_types=1);

namespace Firehed\PhpLsp\Resolution;

use Firehed\PhpLsp\Domain\FunctionInfo;
use Firehed\PhpLsp\Domain\ParameterInfo;
use Firehed\PhpLsp\Domain\PrimitiveType;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(CallContext::class)]
final class CallContextTest extends TestCase
{
    public function testConstruction(): void
    {
        $callable = $this->createResolvedCallable();
        $context = new CallContext(
            callable: $callable,
            activeParameterIndex: 1,
            usedParameterNames: ['name'],
        );

        self::assertSame($callable, $context->callable);
        self::assertSame(1, $context->activeParameterIndex);
        self::assertSame(['name'], $context->usedParameterNames);
    }

    public function testZeroIndexFirstParameter(): void
    {
        $callable = $this->createResolvedCallable();
        $context = new CallContext(
            callable: $callable,
            activeParameterIndex: 0,
            usedParameterNames: [],
        );

        self::assertSame(0, $context->activeParameterIndex);
        self::assertSame([], $context->usedParameterNames);
    }

    private function createResolvedCallable(): ResolvedFunction
    {
        $functionInfo = new FunctionInfo(
            name: 'testFunc',
            returnType: new PrimitiveType('void'),
            parameters: [
                new ParameterInfo(
                    name: 'name',
                    type: new PrimitiveType('string'),
                    hasDefault: false,
                    defaultValue: null,
                    position: 0,
                    isVariadic: false,
                    isPassedByReference: false,
                ),
                new ParameterInfo(
                    name: 'age',
                    type: new PrimitiveType('int'),
                    hasDefault: true,
                    defaultValue: '0',
                    position: 1,
                    isVariadic: false,
                    isPassedByReference: false,
                ),
            ],
            docblock: null,
            file: null,
            line: null,
        );

        return new ResolvedFunction($functionInfo);
    }
}
