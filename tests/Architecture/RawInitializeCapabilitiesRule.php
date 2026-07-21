<?php

declare(strict_types=1);

namespace Firehed\PhpLsp\Tests\Architecture;

use PhpParser\Node;
use PhpParser\Node\Expr\ArrayDimFetch;
use PhpParser\Node\Scalar\String_;
use PHPStan\Analyser\Scope;
use PHPStan\Rules\Rule;
use PHPStan\Rules\RuleErrorBuilder;

/**
 * The RFC 1 §8.1 mechanism for §4.8: the raw `initialize` parameters are
 * reachable only within the negotiation component.
 *
 * `capabilities` is the [LSP] `InitializeParams` field that carries them
 * ("Server lifecycle" → `initialize`), so reading that key anywhere else is a
 * component re-inspecting the raw parameters instead of querying the
 * `SessionCapabilities` value resolved once during negotiation.
 *
 * @implements Rule<ArrayDimFetch>
 */
final class RawInitializeCapabilitiesRule implements Rule
{
    private const string CAPABILITIES_FIELD = 'capabilities';
    private const string NEGOTIATION_NAMESPACE = 'Firehed\PhpLsp\Capability';

    public function getNodeType(): string
    {
        return ArrayDimFetch::class;
    }

    public function processNode(Node $node, Scope $scope): array
    {
        $dim = $node->dim;
        if (!$dim instanceof String_ || $dim->value !== self::CAPABILITIES_FIELD) {
            return [];
        }

        if ($scope->getNamespace() === self::NEGOTIATION_NAMESPACE) {
            return [];
        }

        $message = sprintf(
            'Raw initialize %s must not be read outside %s; query SessionCapabilities instead (RFC 1 §4.8).',
            self::CAPABILITIES_FIELD,
            self::NEGOTIATION_NAMESPACE,
        );

        return [
            RuleErrorBuilder::message($message)
                ->identifier('phpLsp.rawInitializeCapabilities')
                ->build(),
        ];
    }
}
