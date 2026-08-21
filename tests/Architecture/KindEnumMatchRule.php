<?php

declare(strict_types=1);

namespace Firehed\PhpLsp\Tests\Architecture;

use PhpParser\Node;
use PhpParser\Node\Expr\Match_;
use PHPStan\Analyser\Scope;
use PHPStan\Rules\Rule;
use PHPStan\Rules\RuleErrorBuilder;
use PHPStan\Type\ObjectType;

/**
 * The RFC 1 §8.1 mechanism for §4.5: consumers MUST NOT use `match` on a
 * symbol-kind enum to branch on resolved symbol kinds.
 *
 * Allowed locations:
 * - The kind enum's own file (internal operations like `normalize()`)
 * - Metadata factories (DefaultClassInfoFactory, ReflectionSymbolInfoFactory)
 * - Classifiers (CompletionItemFactory)
 * - Tests
 *
 * @implements Rule<Match_>
 */
final class KindEnumMatchRule implements Rule
{
    /**
     * Adding an entry tightens. Removing one loosens (human only). See
     * docs/architecture/enforcement-edits.md.
     *
     * @var list<class-string>
     */
    private const array CONFINED_KIND_ENUMS = [
        \Firehed\PhpLsp\Domain\NameKind::class,
        \Firehed\PhpLsp\Domain\MemberKind::class,
        \Firehed\PhpLsp\Domain\ClassKind::class,
    ];

    /**
     * Adding an entry loosens (human only). Removing one tightens. Renaming one
     * is lateral only when the same PR moves the file. See
     * docs/architecture/enforcement-edits.md.
     */
    private const array ALLOWED_FILES = [
        'src/Domain/NameKind.php',
        'src/Domain/MemberKind.php',
        'src/Domain/ClassKind.php',
        'src/Domain/ClassInfo.php',
        'src/Resolution/NameContext.php',
        'src/Repository/DefaultClassInfoFactory.php',
        'src/Knowledge/ReflectionSymbolInfoFactory.php',
        'src/Knowledge/DeclarationSymbolInfoFactory.php',
        'src/Completion/CompletionItemFactory.php',
    ];

    public function getNodeType(): string
    {
        return Match_::class;
    }

    public function processNode(Node $node, Scope $scope): array
    {
        if (ConfinedFile::isExempt($scope->getFile(), self::ALLOWED_FILES)) {
            return [];
        }

        $condType = $scope->getType($node->cond);

        foreach (self::CONFINED_KIND_ENUMS as $kindEnum) {
            $kindType = new ObjectType($kindEnum);
            if ($kindType->isSuperTypeOf($condType)->yes()) {
                $shortName = $this->shortName($kindEnum);
                $message = sprintf(
                    'match on %s branches per symbol kind; use predicates (RFC 1 §4.5).',
                    $shortName,
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

    private function shortName(string $className): string
    {
        $parts = explode('\\', $className);
        return end($parts);
    }
}
