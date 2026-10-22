<?php

declare(strict_types=1);

namespace Firehed\PhpLsp\Tests\Completion;

use Firehed\PhpLsp\Completion\CompletionItemFactory;
use Firehed\PhpLsp\Completion\NamedArgumentCandidates;
use Firehed\PhpLsp\Domain\ParameterInfo;
use Firehed\PhpLsp\Domain\PrimitiveType;
use Firehed\PhpLsp\Domain\ResolvedCallableInterface;
use Firehed\PhpLsp\Resolution\CallContext;
use Firehed\PhpLsp\Resolution\ResolvedSymbolPresenter;
use Firehed\PhpLsp\Tests\BuildsSymbolInfoTrait;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

#[CoversClass(NamedArgumentCandidates::class)]
final class NamedArgumentCandidatesTest extends TestCase
{
    use BuildsSymbolInfoTrait;

    /**
     * @return iterable<string, array{list<string>, int, string, array<string, string>}>
     */
    public static function callCases(): iterable
    {
        $all = ['name:' => 'string $name', 'count:' => 'int $count', 'active:' => 'bool $active'];
        yield 'nothing supplied; variadic never offered' => [[], 0, '', $all];
        yield 'filled positionally' => [[], 1, '', ['count:' => 'int $count', 'active:' => 'bool $active']];
        yield 'used by name' => [['count'], 0, '', ['name:' => 'string $name', 'active:' => 'bool $active']];
        yield 'positional and named' => [['count'], 1, '', ['active:' => 'bool $active']];
        yield 'prefix' => [[], 0, 'n', ['name:' => 'string $name']];
    }

    /**
     * @param list<string> $usedNames
     * @param array<string, string> $expected Label to detail.
     */
    #[DataProvider('callCases')]
    public function testOffersEachUnsuppliedParameterMatchingThePrefix(
        array $usedNames,
        int $positionallyFilled,
        string $prefix,
        array $expected,
    ): void {
        $callable = self::createStub(ResolvedCallableInterface::class);
        $callable->method('getParameters')->willReturn([
            self::parameterInfo('name', new PrimitiveType('string'), 0),
            self::parameterInfo('count', new PrimitiveType('int'), 1),
            self::parameterInfo('active', new PrimitiveType('bool'), 2),
            new ParameterInfo('values', new PrimitiveType('string'), null, 3, true, false),
        ]);
        $items = (new NamedArgumentCandidates(new CompletionItemFactory(new ResolvedSymbolPresenter())))
            ->find(new CallContext($callable, 0, $usedNames, $positionallyFilled), $prefix);

        self::assertSame(
            $expected,
            array_column($items, 'detail', 'label'),
            'only parameters not yet supplied, not variadic, and matching the prefix are offered',
        );
    }
}
