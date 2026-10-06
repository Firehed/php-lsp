<?php

declare(strict_types=1);

namespace Firehed\PhpLsp\Tests\Completion;

use Firehed\PhpLsp\Completion\CompletionRequest;
use Firehed\PhpLsp\Completion\NamedArgumentCandidates;
use Firehed\PhpLsp\Document\TextDocument;
use Firehed\PhpLsp\Domain\ParameterInfo;
use Firehed\PhpLsp\Domain\PrimitiveType;
use Firehed\PhpLsp\Domain\ResolvedCallableInterface;
use Firehed\PhpLsp\Resolution\CallContext;
use Firehed\PhpLsp\Resolution\CodeResolverInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

#[CoversClass(NamedArgumentCandidates::class)]
final class NamedArgumentCandidatesTest extends TestCase
{
    /**
     * @return iterable<string, array{list<string>, int, string, array<string, string>}>
     */
    public static function callCases(): iterable
    {
        $all = ['name:' => 'string $name', 'count:' => 'int $count', 'active:' => 'bool $active'];
        yield 'nothing supplied; variadic never offered' => [[], 0, 'foo(', $all];
        yield 'filled positionally' => [[], 1, "foo('x', ", ['count:' => 'int $count', 'active:' => 'bool $active']];
        yield 'used by name' => [['count'], 0, 'foo(', ['name:' => 'string $name', 'active:' => 'bool $active']];
        yield 'positional and named' => [['count'], 1, "foo('x', ", ['active:' => 'bool $active']];
        yield 'prefix' => [[], 0, 'foo(n', ['name:' => 'string $name']];
    }

    /**
     * @param list<string> $usedNames
     * @param array<string, string> $expected Label to detail.
     */
    #[DataProvider('callCases')]
    public function testOffersEachUnsuppliedParameterMatchingThePrefix(
        array $usedNames,
        int $positionallyFilled,
        string $line,
        array $expected,
    ): void {
        $callable = self::createStub(ResolvedCallableInterface::class);
        $callable->method('getParameters')->willReturn([
            self::parameter('name', 'string', 0),
            self::parameter('count', 'int', 1),
            self::parameter('active', 'bool', 2),
            new ParameterInfo('values', new PrimitiveType('string'), false, null, 3, true, false),
        ]);
        $codeResolver = self::createStub(CodeResolverInterface::class);
        $codeResolver->method('getCallContext')
            ->willReturn(new CallContext($callable, 0, $usedNames, $positionallyFilled));
        $doc = new TextDocument('file:///t.php', 'php', 0, "<?php\n{$line}");

        $items = (new NamedArgumentCandidates($codeResolver))->find(new CompletionRequest($doc, 1, strlen($line)));

        self::assertNotNull($items, 'a position inside a call offers named arguments');
        self::assertSame(
            $expected,
            array_column($items, 'detail', 'label'),
            'only parameters not yet supplied, not variadic, and matching the prefix are offered',
        );
    }

    public function testReturnsNullOutsideCallContext(): void
    {
        // The composite gates this before dispatching, but the class's contract
        // promises null-when-not-applicable so a direct caller can rely on it.
        $codeResolver = self::createStub(CodeResolverInterface::class);
        $codeResolver->method('getCallContext')->willReturn(null);

        $doc = new TextDocument('file:///t.php', 'php', 0, '<?php $x');
        $request = new CompletionRequest($doc, 0, 8);

        self::assertNull(
            (new NamedArgumentCandidates($codeResolver))->find($request),
            'a position outside any call offers no named arguments',
        );
    }

    private static function parameter(string $name, string $type, int $position): ParameterInfo
    {
        return new ParameterInfo($name, new PrimitiveType($type), false, null, $position, false, false);
    }
}
