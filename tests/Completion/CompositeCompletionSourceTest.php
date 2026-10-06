<?php

declare(strict_types=1);

namespace Firehed\PhpLsp\Tests\Completion;

use Firehed\PhpLsp\Capability\SessionCapabilities;
use Firehed\PhpLsp\Capability\SessionCapabilitiesProviderInterface;
use Firehed\PhpLsp\Completion\CompletionRequest;
use Firehed\PhpLsp\Completion\CompositeCompletionSource;
use Firehed\PhpLsp\Document\TextDocument;
use Firehed\PhpLsp\Domain\ClasslikeName;
use Firehed\PhpLsp\Domain\Location;
use Firehed\PhpLsp\Domain\NameKind;
use Firehed\PhpLsp\Domain\NamespaceContents;
use Firehed\PhpLsp\Domain\ParameterInfo;
use Firehed\PhpLsp\Domain\PrimitiveType;
use Firehed\PhpLsp\Domain\ResolvedCallableInterface;
use Firehed\PhpLsp\Domain\Symbol;
use Firehed\PhpLsp\Domain\SymbolKind;
use Firehed\PhpLsp\Knowledge\SymbolSourceInterface;
use Firehed\PhpLsp\Resolution\CallContext;
use Firehed\PhpLsp\Resolution\CodeResolverInterface;
use Firehed\PhpLsp\Resolution\NameContext;
use Firehed\PhpLsp\Resolution\ResolvedVariable;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

#[CoversClass(CompositeCompletionSource::class)]
final class CompositeCompletionSourceTest extends TestCase
{
    use WiresCompletionSourceTrait;

    public function testVariableInsideACallOffersNamedArgumentsAndVariablesOnly(): void
    {
        $callable = self::createStub(ResolvedCallableInterface::class);
        $callable->method('getParameters')->willReturn([
            new ParameterInfo('name', new PrimitiveType('string'), false, null, 0, false, false),
        ]);
        $codeResolver = self::createStub(CodeResolverInterface::class);
        $codeResolver->method('getCallContext')->willReturn(new CallContext($callable, 0, []));
        $codeResolver->method('getVariablesInScope')->willReturn([
            new ResolvedVariable('variable', new PrimitiveType('string')),
        ]);
        $codeResolver->method('getNameContext')->willReturn(new NameContext(''));
        $symbols = self::createStub(SymbolSourceInterface::class);
        $symbols->method('childrenOf')->willReturn(new NamespaceContents());
        $symbols->method('search')->willReturn([self::symbol('var_dump', SymbolKind::Function_)]);

        self::assertSame(
            ['name:', '$variable'],
            self::labelsAfter('foo($va', $symbols, $codeResolver),
            'a variable being typed in a call offers argument names and variables, not expressions',
        );
    }

    /**
     * @return iterable<string, array{string, list<string>}>
     */
    public static function typePositions(): iterable
    {
        $common = [
            'string', 'int', 'float', 'bool', 'array', 'object',
            'mixed', 'null', 'callable', 'iterable', 'true', 'false',
        ];
        yield 'return type' => [
            'function foo(): ',
            [...$common, 'void', 'never', 'self', 'static', 'parent', 'Widget'],
        ];
        yield 'parameter type' => ['function foo(', [...$common, 'self', 'parent', 'Widget']];
        yield 'property type' => ['class Foo { private ?', [...$common, 'Widget']];
        yield 'after a visibility keyword' => [
            'class Foo { private ',
            ['function', 'static', 'readonly', 'const', ...$common, 'Widget'],
        ];
    }

    /**
     * @param list<string> $expected
     */
    #[DataProvider('typePositions')]
    public function testATypePositionOffersTypesOnly(string $line, array $expected): void
    {
        $codeResolver = self::createStub(CodeResolverInterface::class);
        $codeResolver->method('getNameContext')->willReturn(new NameContext(''));
        $codeResolver->method('isValidTypeHint')->willReturnCallback(
            static fn (ClasslikeName $name): bool => !$name->equals(ClasslikeName::fromFullyQualified('Mixin')),
        );
        $symbols = self::createStub(SymbolSourceInterface::class);
        $symbols->method('childrenOf')->willReturn(new NamespaceContents());
        $symbols->method('search')->willReturnCallback(
            static fn (string $prefix, NameKind $kind): array => match ($kind) {
                NameKind::ClassLike => [
                    self::symbol('Widget', SymbolKind::Class_),
                    self::symbol('Mixin', SymbolKind::Trait_),
                ],
                NameKind::Function_ => [self::symbol('strlen', SymbolKind::Function_)],
                NameKind::Constant => [self::symbol('PHP_VERSION', SymbolKind::Constant)],
            },
        );

        self::assertSame(
            $expected,
            self::labelsAfter($line, $symbols, $codeResolver),
            'built-in types valid there and type-hintable class-likes, plus modifiers after visibility; '
                . 'no traits, functions, or constants',
        );
    }

    /**
     * @return list<string>
     */
    private static function labelsAfter(
        string $line,
        SymbolSourceInterface $symbols,
        CodeResolverInterface $codeResolver,
    ): array {
        $capabilities = self::createStub(SessionCapabilitiesProviderInterface::class);
        $capabilities->method('getSessionCapabilities')->willReturn(new SessionCapabilities());
        $document = new TextDocument('file:///t.php', 'php', 0, "<?php\n{$line}");
        $request = new CompletionRequest($document, 1, strlen($line));

        return array_column(self::completionSourceFor($symbols, $codeResolver, $capabilities)->find($request), 'label');
    }

    private static function symbol(string $name, SymbolKind $kind): Symbol
    {
        return new Symbol($name, $name, $kind, new Location('file:///f.php', 0, 0, 0, 0));
    }
}
