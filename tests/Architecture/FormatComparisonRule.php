<?php

declare(strict_types=1);

namespace Firehed\PhpLsp\Tests\Architecture;

use PhpParser\Node;
use PhpParser\Node\Expr\BinaryOp;
use PhpParser\Node\Expr\MethodCall;
use PhpParser\Node\Identifier;
use PHPStan\Analyser\Scope;
use PHPStan\Rules\Rule;
use PHPStan\Rules\RuleErrorBuilder;

/**
 * Comparing a formatted type string (`$type->format() === 'int'`) branches on
 * the type's display representation, which can diverge from the type's actual
 * identity. Use Type predicates or instanceof checks (which the existing rules
 * confine) instead.
 *
 * @implements Rule<BinaryOp>
 */
final class FormatComparisonRule implements Rule
{
    /**
     * Adding an entry loosens (human only). See
     * docs/architecture/enforcement-edits.md.
     *
     * @var list<string>
     */
    private const array ALLOWED_FILES = [];

    public function getNodeType(): string
    {
        return BinaryOp::class;
    }

    public function processNode(Node $node, Scope $scope): array
    {
        if (!$this->isEquality($node)) {
            return [];
        }

        $hasFormatCall = $this->isFormatCall($node->left) || $this->isFormatCall($node->right);
        if (!$hasFormatCall) {
            return [];
        }

        if (ConfinedFile::isExempt($scope->getFile(), self::ALLOWED_FILES)) {
            return [];
        }

        return [
            RuleErrorBuilder::message(
                'comparing format() output branches on display representation; use Type predicates (RFC 1 §4.5).',
            )
                ->identifier('phpLsp.formatComparison')
                ->build(),
        ];
    }

    private function isEquality(BinaryOp $node): bool
    {
        return $node instanceof BinaryOp\Identical
            || $node instanceof BinaryOp\NotIdentical
            || $node instanceof BinaryOp\Equal
            || $node instanceof BinaryOp\NotEqual;
    }

    private function isFormatCall(Node $node): bool
    {
        if (!$node instanceof MethodCall) {
            return false;
        }
        if (!$node->name instanceof Identifier) {
            return false;
        }

        return $node->name->toLowerString() === 'format';
    }
}
