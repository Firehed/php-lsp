<?php

declare(strict_types=1);

namespace Firehed\PhpLsp\Tests\EndToEnd\Result;

use PHPUnit\Framework\Assert;
use stdClass;

/**
 * One signature of a signature help answer.
 */
final readonly class Signature
{
    /**
     * @param int|null $activeParameter Null when the signature has no parameters.
     */
    public function __construct(
        public string $label,
        public ?string $documentation,
        public ?int $activeParameter,
    ) {
    }

    /**
     * Decodes an [LSP] SignatureInformation. Its own `activeParameter` wins
     * over the answer's.
     */
    public static function fromWire(mixed $wire, mixed $answerActiveParameter): self
    {
        Assert::assertInstanceOf(stdClass::class, $wire, 'a SignatureInformation is an object');
        $label = $wire->label ?? null;
        Assert::assertIsString($label, 'a SignatureInformation has a label');
        $parameters = $wire->parameters ?? [];
        Assert::assertIsList($parameters, 'signature parameters are a list');
        $activeParameter = $wire->activeParameter
            ?? self::answerActiveParameter($answerActiveParameter, count($parameters));
        Assert::assertTrue(
            $activeParameter === null || is_int($activeParameter),
            'the active parameter is an index',
        );

        return new self($label, self::documentationOf($wire->documentation ?? null), $activeParameter);
    }

    /**
     * [LSP] SignatureHelp.activeParameter: omitted or out of range, it
     * defaults to 0; with no parameters, it is ignored.
     */
    private static function answerActiveParameter(mixed $index, int $parameterCount): ?int
    {
        if ($parameterCount === 0) {
            return null;
        }
        Assert::assertTrue($index === null || is_int($index), 'the active parameter is an index');

        return $index !== null && $index < $parameterCount ? $index : 0;
    }

    /**
     * Documentation is a string or MarkupContent.
     */
    private static function documentationOf(mixed $documentation): ?string
    {
        if ($documentation === null || is_string($documentation)) {
            return $documentation;
        }
        Assert::assertInstanceOf(stdClass::class, $documentation, 'documentation is a string or MarkupContent');
        $value = $documentation->value ?? null;
        Assert::assertIsString($value, 'MarkupContent has a value');

        return $value;
    }
}
