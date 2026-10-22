<?php

declare(strict_types=1);

namespace Firehed\PhpLsp\Handler;

use Firehed\PhpLsp\Document\DocumentSourceInterface;
use Firehed\PhpLsp\Protocol\Message;
use Firehed\PhpLsp\Protocol\TextDocumentPositionParams;
use Firehed\PhpLsp\Resolution\CallContext;
use Firehed\PhpLsp\Resolution\Position\CallAtPositionInterface;
use Firehed\PhpLsp\Resolution\ResolvedSymbolPresenterInterface;

/**
 * @phpstan-type ParameterInfoShape array{label: string}
 * @phpstan-type SignatureInfo array{
 *   label: string,
 *   documentation?: string,
 *   parameters?: list<ParameterInfoShape>,
 * }
 */
final class SignatureHelpHandler implements DocumentFeatureHandlerInterface
{
    use SupportsOwnMethodTrait;

    public function __construct(
        private readonly DocumentSourceInterface $documents,
        private readonly CallAtPositionInterface $calls,
        private readonly ResolvedSymbolPresenterInterface $presenter,
    ) {
    }

    public static function method(): string
    {
        return 'textDocument/signatureHelp';
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

        $document = $this->documents->read($position->uri);
        if ($document === null) {
            return null;
        }

        $context = $this->calls->callAt($document, $document->offsetAt($position->line, $position->character));
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
        $presented = $this->presenter->present($callable);

        $signature = [
            'label' => $presented->signature,
            'parameters' => array_map(
                fn($p) => ['label' => $this->presenter->present($p)->signature],
                $callable->getParameters(),
            ),
        ];

        if ($presented->documentation !== null) {
            $signature['documentation'] = $presented->documentation;
        }

        return [
            'signatures' => [$signature],
            'activeSignature' => 0,
            'activeParameter' => $context->activeParameterIndex,
        ];
    }
}
