<?php

declare(strict_types=1);

namespace Firehed\PhpLsp\Tests\Architecture;

use PhpParser\Node;
use PhpParser\Node\Arg;
use PhpParser\Node\Expr;
use PhpParser\Node\Expr\BinaryOp;
use PhpParser\Node\Expr\FuncCall;
use PhpParser\Node\Expr\Match_;
use PhpParser\Node\Name;
use PhpParser\Node\Stmt\Switch_;
use PHPStan\Analyser\Scope;
use PHPStan\Rules\Rule;
use PHPStan\Rules\RuleErrorBuilder;
use PHPStan\Type\ObjectType;
use PHPStan\Type\TypeCombinator;

/**
 * The RFC 1 §8.1 mechanism for §4.5: consumers MUST NOT branch on a
 * symbol-kind enum. Every form that compares a kind is a branch: `match`,
 * `switch`, the four equality operators, and `in_array`.
 *
 * Allowed locations: a kind enum's own methods, where a predicate lives; the
 * metadata factories; and the classifier.
 *
 * @implements Rule<Node>
 */
final class KindBranchRule implements Rule
{
    /**
     * Adding an entry tightens. Removing one loosens (human only). See
     * docs/architecture/enforcement-edits.md.
     *
     * @var list<class-string>
     */
    private const array CONFINED_KIND_ENUMS = [
        \Firehed\PhpLsp\Completion\CompletionItemKind::class,
        \Firehed\PhpLsp\Domain\ClassKind::class,
        \Firehed\PhpLsp\Domain\MemberFilter::class,
        \Firehed\PhpLsp\Domain\MemberKind::class,
        \Firehed\PhpLsp\Domain\NameKind::class,
        \Firehed\PhpLsp\Index\SymbolKind::class,
        \Firehed\PhpLsp\Resolution\MemberAccessKind::class,
    ];

    /**
     * Adding an entry loosens (human only). Removing one tightens. Renaming one
     * is lateral only when the same PR moves the file. See
     * docs/architecture/enforcement-edits.md.
     */
    private const array ALLOWED_FILES = [
        'src/Domain/NameKind.php',
        'src/Domain/MemberKind.php',
        'src/Domain/ClassInfo.php',
        'src/Resolution/NameContext.php',
        'src/Repository/DefaultClassInfoFactory.php',
        'src/Knowledge/ReflectionSymbolInfoFactory.php',
        'src/Knowledge/DeclarationSymbolInfoFactory.php',
        'src/Completion/CompletionItemFactory.php',
    ];

    public function getNodeType(): string
    {
        return Node::class;
    }

    public function processNode(Node $node, Scope $scope): array
    {
        $branch = $this->branch($node);
        if ($branch === null) {
            return [];
        }
        [$form, $operands] = $branch;

        if (ConfinedFile::isExempt($scope->getFile(), self::ALLOWED_FILES)) {
            return [];
        }

        foreach ($operands as $operand) {
            $type = $scope->getType($operand);
            if ($type->isNull()->yes()) {
                continue;
            }
            $type = TypeCombinator::removeNull($type);
            foreach (self::CONFINED_KIND_ENUMS as $kindEnum) {
                if (!(new ObjectType($kindEnum))->isSuperTypeOf($type)->yes()) {
                    continue;
                }
                $message = sprintf(
                    '%s on %s branches per symbol kind; use predicates (RFC 1 §4.5).',
                    $form,
                    $this->shortName($kindEnum),
                );

                return [
                    RuleErrorBuilder::message($message)
                        ->identifier('phpLsp.kindInspection')
                        ->build(),
                ];
            }
        }

        return [];
    }

    /**
     * The branch form this node is, and the expressions whose kind it branches on.
     *
     * @return array{string, list<Expr>}|null
     */
    private function branch(Node $node): ?array
    {
        if ($node instanceof Match_) {
            return ['match', [$node->cond]];
        }
        if ($node instanceof Switch_) {
            return ['switch', [$node->cond]];
        }
        $isEquality = $node instanceof BinaryOp\Identical || $node instanceof BinaryOp\NotIdentical
            || $node instanceof BinaryOp\Equal || $node instanceof BinaryOp\NotEqual;
        if ($isEquality) {
            return [$node->getOperatorSigil(), [$node->left, $node->right]];
        }
        if ($node instanceof FuncCall && $node->name instanceof Name && $node->name->toLowerString() === 'in_array') {
            $needle = $node->args[0] ?? null;
            if ($needle instanceof Arg) {
                return ['in_array', [$needle->value]];
            }
        }

        return null;
    }

    private function shortName(string $className): string
    {
        $parts = explode('\\', $className);
        return end($parts);
    }
}
