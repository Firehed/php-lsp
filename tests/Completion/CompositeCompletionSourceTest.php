<?php

declare(strict_types=1);

namespace Firehed\PhpLsp\Tests\Completion;

use Firehed\PhpLsp\Capability\SessionCapabilities;
use Firehed\PhpLsp\Capability\SessionCapabilitiesProviderInterface;
use Firehed\PhpLsp\Completion\CompletionRequest;
use Firehed\PhpLsp\Completion\CompositeCompletionSource;
use Firehed\PhpLsp\Document\TextDocument;
use Firehed\PhpLsp\Domain\Location;
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
        $symbols->method('search')->willReturn([
            new Symbol('var_dump', 'var_dump', SymbolKind::Function_, new Location('file:///f.php', 0, 0, 0, 0)),
        ]);
        $capabilities = self::createStub(SessionCapabilitiesProviderInterface::class);
        $capabilities->method('getSessionCapabilities')->willReturn(new SessionCapabilities());
        $source = self::completionSourceFor($symbols, $codeResolver, $capabilities);
        $line = 'foo($va';
        $document = new TextDocument('file:///t.php', 'php', 0, "<?php\n{$line}");

        $items = $source->find(new CompletionRequest($document, 1, strlen($line)));

        self::assertSame(
            ['name:', '$variable'],
            array_column($items, 'label'),
            'a variable being typed in a call offers argument names and variables, not expressions',
        );
    }
}
