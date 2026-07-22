<?php

declare(strict_types=1);

namespace Firehed\PhpLsp\Tests\Architecture;

use PhpParser\Node;
use PhpParser\Node\Expr\ArrayDimFetch;
use PhpParser\Node\Scalar\String_;
use PHPStan\Analyser\Scope;
use PHPStan\Rules\Rule;
use PHPStan\Rules\RuleErrorBuilder;
use PHPStan\Type\MixedType;

/**
 * The RFC 1 §8.1 mechanism for §4.8: the raw `initialize` parameters are
 * reachable only within the negotiation component.
 *
 * `capabilities` (client capabilities, §4.8) and `general` (which carries
 * `positionEncodings`, §4.9) are the [LSP] `InitializeParams` fields the
 * negotiation reads ("Server lifecycle" → `initialize`), so reading either key
 * anywhere else is a component re-inspecting the raw parameters instead of
 * querying the `SessionCapabilities` value resolved once during negotiation.
 *
 * @implements Rule<ArrayDimFetch>
 */
final class RawInitializeCapabilitiesRule implements Rule
{
    /** @var list<string> */
    private const array CONFINED_FIELDS = ['capabilities', 'general'];
    private const string NEGOTIATION_NAMESPACE = 'Firehed\PhpLsp\Capability';

    public function getNodeType(): string
    {
        return ArrayDimFetch::class;
    }

    public function processNode(Node $node, Scope $scope): array
    {
        $dim = $node->dim;
        if (!$dim instanceof String_ || !in_array($dim->value, self::CONFINED_FIELDS, true)) {
            return [];
        }

        if ($scope->getNamespace() === self::NEGOTIATION_NAMESPACE) {
            return [];
        }

        // The raw params are `array<array-key, mixed>`, so a genuine read of
        // them yields `mixed`. A read that resolves to a concrete type is
        // indexing one of the server's own typed structures — the `capabilities`
        // of an outgoing InitializeResult, say — which is not an initialize
        // parameter at all and which §4.8 says nothing about.
        if (!$scope->getType($node) instanceof MixedType) {
            return [];
        }

        $message = sprintf(
            'Raw initialize %s must not be read outside %s; query SessionCapabilities instead (RFC 1 §4.8).',
            $dim->value,
            self::NEGOTIATION_NAMESPACE,
        );

        return [
            RuleErrorBuilder::message($message)
                ->identifier('phpLsp.rawInitializeCapabilities')
                ->build(),
        ];
    }
}
