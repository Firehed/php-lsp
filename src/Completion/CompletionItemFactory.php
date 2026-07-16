<?php

declare(strict_types=1);

namespace Firehed\PhpLsp\Completion;

use Firehed\PhpLsp\Domain\FunctionInfo;
use Firehed\PhpLsp\Domain\ParameterInfo;
use Firehed\PhpLsp\Protocol\Range;
use Firehed\PhpLsp\Resolution\ResolvedConstant;
use Firehed\PhpLsp\Resolution\ResolvedEnumCase;
use Firehed\PhpLsp\Resolution\ResolvedMember;
use Firehed\PhpLsp\Resolution\ResolvedMethod;
use Firehed\PhpLsp\Resolution\ResolvedProperty;
use Firehed\PhpLsp\Utility\DocblockParser;
use Firehed\PhpLsp\Utility\NamespacePath;

/**
 * Builds LSP completion items. Centralizing construction here keeps item shape,
 * kind assignment, and documentation extraction consistent across every
 * completion source.
 *
 * @phpstan-import-type LspRange from Range
 * @phpstan-type CompletionItem array{
 *   label: string,
 *   kind?: int,
 *   detail?: string,
 *   documentation?: string,
 *   filterText?: string,
 *   textEdit?: array{range: LspRange, newText: string},
 * }
 */
final class CompletionItemFactory
{
    /**
     * @return CompletionItem
     */
    public static function forResolvedMember(ResolvedMember $member): array
    {
        $kind = match (true) {
            $member instanceof ResolvedMethod => CompletionItemKind::Method,
            $member instanceof ResolvedProperty => CompletionItemKind::Property,
            $member instanceof ResolvedConstant => CompletionItemKind::Constant,
            $member instanceof ResolvedEnumCase => CompletionItemKind::EnumMember,
            // @codeCoverageIgnoreStart
            default => throw new \LogicException('Unexpected member type: ' . $member::class),
            // @codeCoverageIgnoreEnd
        };

        $item = [
            'label' => $member->getName()->name,
            'kind' => $kind->value,
            'detail' => $member->format(),
        ];

        $doc = $member->getDocumentation();
        if ($doc !== null) {
            $item['documentation'] = $doc;
        }

        return $item;
    }

    /**
     * @return CompletionItem
     */
    public static function forFunction(FunctionInfo $function): array
    {
        return self::withDocumentation([
            'label' => $function->name,
            'kind' => CompletionItemKind::Function->value,
            'detail' => $function->format(),
        ], $function->docblock);
    }

    /**
     * @return CompletionItem
     */
    public static function forBuiltinFunction(string $name): array
    {
        return [
            'label' => $name,
            'kind' => CompletionItemKind::Function->value,
        ];
    }

    /**
     * @return CompletionItem
     */
    public static function forClass(
        string $reference,
        string $fullyQualifiedName,
        Range $replaceRange,
        ?string $filterText = null,
    ): array {
        return [
            'label' => $reference,
            'kind' => CompletionItemKind::Class_->value,
            'detail' => $fullyQualifiedName,
            // Clients filter on the short name by default, so a relative reference
            // like `Sub\Thing` still matches when only `Thing` has been typed. An
            // inlined navigation entry overrides this with its qualified reference,
            // since the user reaches it by typing the parent segment, not the leaf.
            'filterText' => $filterText ?? NamespacePath::shortNameOf($reference),
            // Replace the whole typed token with the reference, so a qualified
            // name never duplicates the segments already on screen.
            'textEdit' => [
                'range' => $replaceRange->toArray(),
                'newText' => $reference,
            ],
        ];
    }

    /**
     * A namespace offered as a navigable node. The label shows the reference plus a
     * separator (`Http\`) as an affordance, but the textEdit inserts the bare
     * reference — so accepting it leaves `\Psr\Http` (cursor before the next `\`).
     * The user then types `\`, an advertised trigger character, and completion
     * re-fires to walk one segment deeper: portable, with no client-specific
     * command.
     *
     * The reference is normally the next segment (`Http`), but an inlined grandchild
     * carries its qualified path (`Small\Deep`) so it navigates from the current
     * point without duplicating segments.
     *
     * @return CompletionItem
     */
    public static function forNamespace(string $reference, string $fullyQualifiedName, Range $replaceRange): array
    {
        return [
            'label' => $reference . '\\',
            'kind' => CompletionItemKind::Module->value,
            'detail' => $fullyQualifiedName,
            'filterText' => $reference,
            'textEdit' => [
                'range' => $replaceRange->toArray(),
                'newText' => $reference,
            ],
        ];
    }

    /**
     * @return CompletionItem
     */
    public static function forKeyword(string $keyword): array
    {
        return [
            'label' => $keyword,
            'kind' => CompletionItemKind::Keyword->value,
        ];
    }

    /**
     * @return CompletionItem
     */
    public static function forBuiltinType(string $type): array
    {
        return [
            'label' => $type,
            'kind' => CompletionItemKind::Keyword->value,
            'detail' => 'builtin type',
        ];
    }

    /**
     * @return CompletionItem
     */
    public static function forVariable(string $name, string $typeLabel): array
    {
        return [
            'label' => '$' . $name,
            'kind' => CompletionItemKind::Variable->value,
            'detail' => $typeLabel,
        ];
    }

    /**
     * @return CompletionItem
     */
    public static function forNamedArgument(ParameterInfo $parameter): array
    {
        return [
            'label' => $parameter->name . ':',
            'kind' => CompletionItemKind::Field->value,
            'detail' => $parameter->format(),
        ];
    }

    /**
     * The `::class` magic constant offered after static access.
     *
     * @return CompletionItem
     */
    public static function forClassConstant(): array
    {
        return [
            'label' => 'class',
            'kind' => CompletionItemKind::Constant->value,
            'detail' => 'string (fully qualified class name)',
        ];
    }

    /**
     * @param CompletionItem $item
     * @return CompletionItem
     */
    private static function withDocumentation(array $item, string|false|null $docText): array
    {
        if ($docText !== null && $docText !== false && $docText !== '') {
            $doc = DocblockParser::extractDescription($docText);
            if ($doc !== '') {
                $item['documentation'] = $doc;
            }
        }
        return $item;
    }
}
