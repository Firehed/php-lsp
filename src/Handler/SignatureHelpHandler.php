<?php

declare(strict_types=1);

namespace Firehed\PhpLsp\Handler;

use Firehed\PhpLsp\Document\DocumentManager;
use Firehed\PhpLsp\Protocol\Message;
use Firehed\PhpLsp\Protocol\TextDocumentPositionParams;
use Firehed\PhpLsp\Resolution\CallContext;
use Firehed\PhpLsp\Resolution\CodeResolver;

/**
 * @phpstan-type ParameterInfoShape array{label: string}
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
        private readonly CodeResolver $codeResolver,
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
        $position = TextDocumentPositionParams::tryFromMessage($message);
        if ($position === null) {
            return null;
        }

        $document = $this->documentManager->get($position->uri);
        if ($document === null) {
            return null;
        }

        $context = $this->codeResolver->getCallContext($document, $position->line, $position->character);
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
