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
    /** @var list<class-string> */
    private const array CONFINED_TYPES = [
        \Firehed\PhpLsp\Domain\ClassName::class,
        \Firehed\PhpLsp\Domain\UnionType::class,
        \Firehed\PhpLsp\Domain\IntersectionType::class,
        \Firehed\PhpLsp\Domain\PrimitiveType::class,
    ];

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

        $file = $scope->getFile();
        if (str_contains($file, '/tests/') && !str_contains($file, '/tests/Architecture/data/')) {
            return [];
        }
        foreach (self::ALLOWED_FILES as $allowed) {
            if (str_ends_with($file, $allowed)) {
                return [];
            }
        }

        $shortName = (new \ReflectionClass($className))->getShortName();
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
}
