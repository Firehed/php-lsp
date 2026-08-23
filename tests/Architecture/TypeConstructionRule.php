<?php

declare(strict_types=1);

namespace Firehed\PhpLsp\Tests\Architecture;

use PhpParser\Node;
use PhpParser\Node\Expr\New_;
use PhpParser\Node\Name;
use PHPStan\Analyser\Scope;
use PHPStan\Rules\Rule;
use PHPStan\Rules\RuleErrorBuilder;

/**
 * The RFC 1 §8.1 mechanism for §4.6: Type objects MUST be constructed through
 * the type factory.
 *
 * @implements Rule<New_>
 */
final class TypeConstructionRule implements Rule
{
    /**
     * Adding an entry tightens. Removing one loosens (human only). See
     * docs/architecture/enforcement-edits.md.
     *
     * @var list<class-string>
     */
    private const array CONFINED_TYPES = [
        \Firehed\PhpLsp\Domain\ClassName::class,
        \Firehed\PhpLsp\Domain\UnionType::class,
        \Firehed\PhpLsp\Domain\IntersectionType::class,
        \Firehed\PhpLsp\Domain\PrimitiveType::class,
        \Firehed\PhpLsp\Domain\LateStaticType::class,
    ];

    /**
     * Adding an entry loosens (human only). Removing one tightens. Renaming one
     * is lateral only when the same PR moves the file. See
     * docs/architecture/enforcement-edits.md.
     */
    private const array ALLOWED_FILES = [
        'src/Domain/TypeFactory.php',
        'src/Domain/ClassName.php',
        'src/Domain/UnionType.php',
        'src/Domain/IntersectionType.php',
        'src/Domain/PrimitiveType.php',
        'src/Domain/LateStaticType.php',
    ];

    public function getNodeType(): string
    {
        return New_::class;
    }

    public function processNode(Node $node, Scope $scope): array
    {
        $class = $node->class;
        if (!$class instanceof Name) {
            return [];
        }

        $className = $scope->resolveName($class);
        if (!in_array($className, self::CONFINED_TYPES, true)) {
            return [];
        }

        if (ConfinedFile::isExempt($scope->getFile(), self::ALLOWED_FILES)) {
            return [];
        }

        $shortName = $this->shortName($className);
        $message = sprintf(
            'new %s is confined to TypeFactory; use TypeFactory methods instead (RFC 1 §4.6).',
            $shortName,
        );

        return [
            RuleErrorBuilder::message($message)
                ->identifier('phpLsp.typeConstruction')
                ->build(),
        ];
    }

    private function shortName(string $className): string
    {
        $parts = explode('\\', $className);
        return end($parts);
    }
}
