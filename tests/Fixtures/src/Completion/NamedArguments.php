<?php

declare(strict_types=1);

namespace Fixtures\Completion;

function proceduralHelper(string $message, int $level = 0, bool $verbose = false): void
{
}

class NamedArguments
{
    public function multipleParams(string $name, int $count, bool $active): void
    {
    }

    public function withDefaults(string $name, int $count = 0, bool $active = true): void
    {
    }

    public function testNamedArgEmpty(): void
    {
        $this->multipleParams(/*|named_empty*/'test', 1, true);
    }

    public function testNamedArgAfterPositional(): void
    {
        $this->multipleParams('value', /*|after_positional*/1, true);
    }

    public function testNamedArgAfterNamed(): void
    {
        $this->multipleParams(name: 'value', /*|after_named*/count: 1);
    }

    public function testNamedArgMiddle(): void
    {
        $this->multipleParams(name: 'value', /*|middle_named*/active: true);
    }

    public function testFunctionCallNamedArg(): void
    {
        array_map(/*|function_named_empty*/fn($x) => $x, []);
    }

    public function testStaticCallNamedArg(): void
    {
        self::staticWithParams(/*|static_named_empty*/'test');
    }

    public function testNewCallNamedArg(): void
    {
        new ParamClass(/*|new_named_empty*/'test');
    }

    public function testIncompleteCallWithPrefix(): void
    {
        $this->multipleParams(n/*|incomplete_with_prefix*/
    }

    public function testMixedPositionalAndNamed(): void
    {
        $this->multipleParams('positional', /*|mixed_positional_named*/count: 1);
    }

    public function testAdditiveWithVariable(): void
    {
        $localVar = 'test';
        $this->multipleParams(/*|additive_with_variable*/);
    }

    public static function staticWithParams(string $value, int $limit = 10): array
    {
        return [];
    }
}

class ParamClass
{
    public function __construct(
        public string $name,
        public int $age = 0,
    ) {
    }
}

// Procedural context tests
proceduralHelper(/*|procedural_empty*/'hello');
proceduralHelper(message: 'hi', /*|procedural_after_named*/level: 5);

// Incomplete expression with prefix (real-world typing scenario)
// Note: This simulates typing $this->post(p| in an editor
