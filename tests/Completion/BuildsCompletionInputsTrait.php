<?php

declare(strict_types=1);

namespace Firehed\PhpLsp\Tests\Completion;

use Firehed\PhpLsp\Capability\SessionCapabilities;
use Firehed\PhpLsp\Capability\SessionCapabilitiesProviderInterface;
use Firehed\PhpLsp\Completion\CompletionRequest;
use Firehed\PhpLsp\Document\TextDocument;

trait BuildsCompletionInputsTrait
{
    /**
     * A request at the end of $code, which follows the open tag.
     */
    private static function requestAfter(string $code): CompletionRequest
    {
        $content = "<?php\n{$code}";
        $lines = explode("\n", $content);

        return new CompletionRequest(
            new TextDocument('file:///t.php', 'php', 0, $content),
            count($lines) - 1,
            strlen(end($lines)),
        );
    }

    private static function capabilitiesProvider(
        SessionCapabilities $capabilities = new SessionCapabilities(),
    ): SessionCapabilitiesProviderInterface {
        $provider = self::createStub(SessionCapabilitiesProviderInterface::class);
        $provider->method('getSessionCapabilities')->willReturn($capabilities);

        return $provider;
    }
}
