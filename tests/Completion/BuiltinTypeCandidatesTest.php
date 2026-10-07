<?php

declare(strict_types=1);

namespace Firehed\PhpLsp\Tests\Completion;

use Firehed\PhpLsp\Completion\BuiltinTypeCandidates;
use Firehed\PhpLsp\Completion\TypeHintContext;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

#[CoversClass(BuiltinTypeCandidates::class)]
final class BuiltinTypeCandidatesTest extends TestCase
{
    use BuildsCompletionInputsTrait;

    private const array COMMON = [
        'string', 'int', 'float', 'bool', 'array', 'object',
        'mixed', 'null', 'callable', 'iterable', 'true', 'false',
    ];

    /**
     * @return iterable<string, array{TypeHintContext, list<string>}>
     */
    public static function contexts(): iterable
    {
        yield 'property' => [TypeHintContext::Property, self::COMMON];
        yield 'parameter' => [TypeHintContext::Parameter, [...self::COMMON, 'self', 'parent']];
        yield 'return' => [TypeHintContext::ReturnType, [...self::COMMON, 'void', 'never', 'self', 'static', 'parent']];
    }

    /**
     * @param list<string> $expected
     */
    #[DataProvider('contexts')]
    public function testOffersTheTypesValidInThePosition(TypeHintContext $context, array $expected): void
    {
        self::assertSame(
            $expected,
            array_column((new BuiltinTypeCandidates())->find(self::requestAfter('foo('), $context), 'label'),
            'each position offers the built-in types PHP accepts there',
        );
    }

    public function testFiltersByThePrefix(): void
    {
        self::assertSame(
            ['string'],
            array_column(
                (new BuiltinTypeCandidates())->find(self::requestAfter('foo(str'), TypeHintContext::Parameter),
                'label',
            ),
            'only types starting with what was typed',
        );
    }
}
