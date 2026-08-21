<?php

declare(strict_types=1);

namespace Firehed\PhpLsp\Tests\Architecture;

use PhpParser\Node;
use PhpParser\Node\Expr\Instanceof_;
use PhpParser\Node\Name;
use PHPStan\Analyser\Scope;
use PHPStan\Rules\Rule;
use PHPStan\Rules\RuleErrorBuilder;

/**
 * The RFC 1 §8.1 mechanism for §4.5: consumers MUST NOT use `instanceof`
 * against a concrete Type or ResolvedSymbol implementation to decide
 * suitability.
 *
 * Allowed locations:
 * - Type implementations (internal operations)
 * - TypeFactory (construction)
 * - Metadata factories (DefaultClassInfoFactory, ReflectionSymbolInfoFactory)
 * - Classifiers (CompletionItemFactory - maps symbol to LSP kind)
 * - Tests
 *
 * @implements Rule<Instanceof_>
 */
final class KindInspectionRule implements Rule
{
    /**
     * Adding an entry tightens. Removing one loosens (human only). See
     * docs/architecture/enforcement-edits.md.
     *
     * @var list<class-string>
     */
    private const array CONFINED_TYPE_IMPLS = [
        \Firehed\PhpLsp\Domain\ClassName::class,
        \Firehed\PhpLsp\Domain\UnionType::class,
        \Firehed\PhpLsp\Domain\IntersectionType::class,
        \Firehed\PhpLsp\Domain\PrimitiveType::class,
        \Firehed\PhpLsp\Domain\LateStaticType::class,
    ];

    /**
     * Adding an entry tightens. Removing one loosens (human only).
     *
     * @var list<class-string>
     */
    private const array CONFINED_RESOLVED_IMPLS = [
        \Firehed\PhpLsp\Resolution\ResolvedClass::class,
        \Firehed\PhpLsp\Resolution\ResolvedConstant::class,
        \Firehed\PhpLsp\Resolution\ResolvedEnumCase::class,
        \Firehed\PhpLsp\Resolution\ResolvedFunction::class,
        \Firehed\PhpLsp\Resolution\ResolvedGlobalConstant::class,
        \Firehed\PhpLsp\Resolution\ResolvedMethod::class,
        \Firehed\PhpLsp\Resolution\ResolvedParameter::class,
        \Firehed\PhpLsp\Resolution\ResolvedProperty::class,
        \Firehed\PhpLsp\Resolution\ResolvedVariable::class,
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
        'src/Repository/DefaultClassInfoFactory.php',
        'src/Knowledge/ReflectionSymbolInfoFactory.php',
        'src/Completion/CompletionItemFactory.php',
    ];

    public function getNodeType(): string
    {
        return Instanceof_::class;
    }

    public function processNode(Node $node, Scope $scope): array
    {
        if (ConfinedFile::isExempt($scope->getFile(), self::ALLOWED_FILES)) {
            return [];
        }

        $class = $node->class;
        if (!$class instanceof Name) {
            return [];
        }

        $className = $scope->resolveName($class);

        if (in_array($className, self::CONFINED_TYPE_IMPLS, true)) {
            $shortName = $this->shortName($className);
            $message = sprintf(
                'instanceof %s branches on concrete Type; use predicates (RFC 1 §4.5).',
                $shortName,
            );
            return [
                RuleErrorBuilder::message($message)
                    ->identifier('phpLsp.kindInspection')
                    ->build(),
            ];
        }

        if (in_array($className, self::CONFINED_RESOLVED_IMPLS, true)) {
            $shortName = $this->shortName($className);
            $message = sprintf(
                'instanceof %s branches on concrete ResolvedSymbol; use predicates (RFC 1 §4.5).',
                $shortName,
            );
            return [
                RuleErrorBuilder::message($message)
                    ->identifier('phpLsp.kindInspection')
                    ->build(),
            ];
        }

        return [];
    }

    private function shortName(string $className): string
    {
        $parts = explode('\\', $className);
        return end($parts);
    }
}
