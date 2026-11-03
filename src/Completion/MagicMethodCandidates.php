<?php

declare(strict_types=1);

namespace Firehed\PhpLsp\Completion;

use Firehed\PhpLsp\Domain\MemberKind;
use Firehed\PhpLsp\Domain\MethodName;
use Firehed\PhpLsp\Domain\PrefixMatcher;
use Firehed\PhpLsp\Knowledge\SymbolSourceInterface;

/**
 * The magic methods a method being declared may be: those its class-like and
 * modifiers allow, less those the class-like already declares.
 *
 * @phpstan-import-type CompletionItem from CompletionItemFormatter
 */
final class MagicMethodCandidates
{
    public function __construct(
        private readonly SymbolSourceInterface $symbols,
        private readonly CompletionItemFormatter $itemFormatter,
    ) {
    }

    /**
     * @return list<CompletionItem>
     */
    public function find(MethodName $declared, string $prefix): array
    {
        $class = $this->symbols->lookupClassLike($declared->owner);
        if ($class === null) {
            return [];
        }
        $declaring = $class->methods[$declared->name] ?? null;
        if ($declaring === null) {
            return [];
        }

        $others = array_diff_key($class->methods, [$declared->name => true]);
        $declaredElsewhere = array_map(MemberKind::Method->normalize(...), array_keys($others));

        $items = [];
        foreach (MagicMethod::cases() as $method) {
            if (
                $method->declarableAs($class->kind, $declaring->isStatic, $declaring->visibility)
                && !in_array(MemberKind::Method->normalize($method->value), $declaredElsewhere, true)
                && PrefixMatcher::matches($method->value, $prefix)
            ) {
                $items[] = $this->itemFormatter->forMagicMethod($method);
            }
        }

        return $items;
    }
}
