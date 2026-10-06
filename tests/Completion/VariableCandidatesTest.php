<?php

declare(strict_types=1);

namespace Firehed\PhpLsp\Tests\Completion;

use Firehed\PhpLsp\Completion\CompletionRequest;
use Firehed\PhpLsp\Completion\VariableCandidates;
use Firehed\PhpLsp\Document\TextDocument;
use Firehed\PhpLsp\Domain\PrimitiveType;
use Firehed\PhpLsp\Resolution\CodeResolverInterface;
use Firehed\PhpLsp\Resolution\ResolvedVariable;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

#[CoversClass(VariableCandidates::class)]
final class VariableCandidatesTest extends TestCase
{
    private const array ALL_IN_SCOPE = ['$name' => 'string', '$age' => 'int', '$untyped' => 'mixed'];

    /**
     * @return iterable<string, array{string, array<string, string>}>
     */
    public static function prefixCases(): iterable
    {
        yield 'prefix after $' => ['$n', ['$name' => 'string']];
        yield 'bare $' => ['$', self::ALL_IN_SCOPE];
        yield 'no $ offers everything in scope' => ['foo(', self::ALL_IN_SCOPE];
    }

    /**
     * @param array<string, string> $expected Label to detail.
     */
    #[DataProvider('prefixCases')]
    public function testOffersInScopeVariablesMatchingThePrefixWithTheirTypes(string $line, array $expected): void
    {
        $resolver = self::createStub(CodeResolverInterface::class);
        $resolver->method('getVariablesInScope')->willReturn([
            new ResolvedVariable('name', new PrimitiveType('string')),
            new ResolvedVariable('age', new PrimitiveType('int')),
            new ResolvedVariable('untyped', null),
        ]);
        $document = new TextDocument('file:///t.php', 'php', 0, "<?php\n{$line}");

        $items = (new VariableCandidates($resolver))->find(new CompletionRequest($document, 1, strlen($line)));

        self::assertSame(
            $expected,
            array_column($items, 'detail', 'label'),
            'each matching variable is offered with its type, or mixed when untyped',
        );
    }
}
