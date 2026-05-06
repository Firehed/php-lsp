<?php

declare(strict_types=1);

namespace Firehed\PhpLsp\Handler;

use Firehed\PhpLsp\Document\DocumentManager;
use Firehed\PhpLsp\Protocol\Message;
use Firehed\PhpLsp\Resolution\CallContext;
use Firehed\PhpLsp\Resolution\SymbolResolver;

/**
 * @phpstan-type ParameterInfoShape array{label: string, documentation?: string}
 * @phpstan-type SignatureInfo array{
 *   label: string,
 *   documentation?: string,
 *   parameters?: list<ParameterInfoShape>,
 * }
 */
final class SignatureHelpHandler implements HandlerInterface
{
    public function __construct(
        private readonly DocumentManager $documentManager,
        private readonly SymbolResolver $symbolResolver,
    ) {
    }

    public function supports(string $method): bool
    {
        return $method === 'textDocument/signatureHelp';
    }

    /**
     * @return array{signatures: list<SignatureInfo>, activeSignature: int, activeParameter: int}|null
     */
    public function handle(Message $message): ?array
    {
        $params = $message->params ?? [];

        $textDocument = $params['textDocument'] ?? [];
        if (!is_array($textDocument)) {
            return null;
        }
        $uri = $textDocument['uri'] ?? '';
        if (!is_string($uri)) {
            return null;
        }

        $position = $params['position'] ?? [];
        if (!is_array($position)) {
            return null;
        }
        $line = $position['line'] ?? 0;
        $character = $position['character'] ?? 0;
        if (!is_int($line) || !is_int($character)) {
            return null;
        }

        $document = $this->documentManager->get($uri);
        if ($document === null) {
            return null;
        }

        $context = $this->symbolResolver->getCallContext($document, $line, $character);
        if ($context === null) {
            return null;
        }

        return $this->formatSignatureHelp($context);
    }

    /**
     * @return array{signatures: list<SignatureInfo>, activeSignature: int, activeParameter: int}
     */
    private function formatSignatureHelp(CallContext $context): array
    {
        $callable = $context->callable;
        $params = $callable->getParameters();

        $signature = [
            'label' => $callable->format(),
            'parameters' => array_map(
                fn($p) => ['label' => $p->format()],
                $params,
            ),
        ];

        $doc = $callable->getDocumentation();
        if ($doc !== null) {
            $signature['documentation'] = $doc;
        }

        return [
            'signatures' => [$signature],
            'activeSignature' => 0,
            'activeParameter' => $context->activeParameterIndex,
        ];
    }
}
