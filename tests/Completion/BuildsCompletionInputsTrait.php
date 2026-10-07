<?php

declare(strict_types=1);

namespace Firehed\PhpLsp\Tests\Completion;

use Firehed\PhpLsp\Capability\SessionCapabilities;
use Firehed\PhpLsp\Capability\SessionCapabilitiesProviderInterface;
use Firehed\PhpLsp\Completion\CompletionRequest;
use Firehed\PhpLsp\Document\TextDocument;
use Firehed\PhpLsp\Domain\Location;
use Firehed\PhpLsp\Domain\NamespaceName;
use Firehed\PhpLsp\Domain\Symbol;
use Firehed\PhpLsp\Domain\SymbolKind;

trait BuildsCompletionInputsTrait
{
    /**
     * A request at the end of $line, the only line after the open tag.
     */
    private static function requestAfter(string $line): CompletionRequest
    {
        return new CompletionRequest(new TextDocument('file:///t.php', 'php', 0, "<?php\n{$line}"), 1, strlen($line));
    }

    private static function capabilitiesProvider(
        SessionCapabilities $capabilities = new SessionCapabilities(),
    ): SessionCapabilitiesProviderInterface {
        $provider = self::createStub(SessionCapabilitiesProviderInterface::class);
        $provider->method('getSessionCapabilities')->willReturn($capabilities);

        return $provider;
    }

    private static function symbol(string $fullyQualifiedName, SymbolKind $kind): Symbol
    {
        return new Symbol(
            NamespaceName::shortNameOf($fullyQualifiedName),
            $fullyQualifiedName,
            $kind,
            new Location('file:///f.php', 0, 0, 0, 0),
        );
    }
}
