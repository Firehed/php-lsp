<?php

declare(strict_types=1);

namespace Firehed\PhpLsp\Completion;

use Firehed\PhpLsp\Domain\MemberKind;
use Firehed\PhpLsp\Domain\NameKind;
use Firehed\PhpLsp\Domain\NamespacePath;
use Firehed\PhpLsp\Domain\ParameterInfo;
use Firehed\PhpLsp\Domain\ResolvedMember;
use Firehed\PhpLsp\Protocol\Range;
use Firehed\PhpLsp\Resolution\PresentedSymbol;
use Firehed\PhpLsp\Resolution\ResolvedSymbolPresenter;

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
 *   sortText?: string,
 *   insertText?: string,
 *   insertTextFormat?: int,
 *   textEdit?: array{range: LspRange, newText: string},
 * }
 */
final class CompletionItemFactory
{
    /**
     * @return CompletionItem
     */
    public static function forResolvedMember(ResolvedMember $member, bool $snippetSupport = false): array
    {
        $memberKind = $member->getMemberKind();
        $itemKind = match ($memberKind) {
            MemberKind::Method => CompletionItemKind::Method,
            MemberKind::Property => CompletionItemKind::Property,
            MemberKind::Constant => CompletionItemKind::Constant,
            MemberKind::EnumCase => CompletionItemKind::EnumMember,
        };

        $presented = ResolvedSymbolPresenter::present($member);
        $item = [
            'label' => $member->getName()->name,
            'kind' => $itemKind->value,
            'detail' => $presented->signature,
        ];

        if ($presented->documentation !== null) {
            $item['documentation'] = $presented->documentation;
        }

        if ($memberKind === MemberKind::Method) {
            $item = self::withCallableSnippet($item, $snippetSupport);
        }

        return $item;
    }

    /**
     * @return CompletionItem
     */
    public static function forSymbol(
        string $reference,
        string $fullyQualifiedName,
        NameKind $kind,
        Range $replaceRange,
        bool $snippetSupport = false,
        ?string $filterText = null,
        ?PresentedSymbol $presented = null,
    ): array {
        $itemKind = match ($kind) {
            NameKind::ClassLike => CompletionItemKind::Class_,
            NameKind::Function_ => CompletionItemKind::Function,
            NameKind::Constant => CompletionItemKind::Constant,
        };

        $item = [
            'label' => $reference,
            'kind' => $itemKind->value,
            'detail' => $presented === null ? $fullyQualifiedName : $presented->signature,
            'filterText' => $filterText ?? NamespacePath::shortNameOf($reference),
            'textEdit' => [
                'range' => $replaceRange->toArray(),
                'newText' => $reference,
            ],
        ];

        if ($presented?->documentation !== null) {
            $item['documentation'] = $presented->documentation;
        }

        if ($kind->isFunction()) {
            $item = self::withCallableSnippet($item, $snippetSupport);
        }

        return $item;
    }

    /**
     * A namespace offered as a navigable node. Both the label and the inserted text
     * are the reference plus a trailing separator (`Http\`): the separator has to be
     * in the inserted text, not just the label, because clients display the text
     * they insert — e.g. Vim/ale sets the menu entry's `word` to `textEdit.newText`
     * and ignores `label`, so a bare segment would render indistinguishably from a
     * same-named class. Accepting the node leaves the cursor after the `\`; typing
     * the next segment fires completion one level deeper.
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
                'newText' => $reference . '\\',
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
        $label = $parameter->name . ':';
        return [
            'label' => $label,
            'kind' => CompletionItemKind::Field->value,
            'detail' => ParameterInfo::signature($parameter),
            // This promotes named args to the top of the list in case it gets
            // capped, since ! sorts alphabetically before the string
            'sortText' => '!' . $label,
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
     * Insert `name($0)` for a callable so the parentheses are typed for the user
     * and the cursor lands between them. Emitted only when the client declared
     * snippet support (RFC 1 §4.8); otherwise the item inserts its bare label, as
     * a plaintext client would show `$0` literally. The label is an identifier, so
     * it needs no snippet escaping.
     *
     * @param CompletionItem $item
     * @return CompletionItem
     */
    private static function withCallableSnippet(array $item, bool $snippetSupport): array
    {
        if (!$snippetSupport) {
            return $item;
        }

        $item['insertText'] = $item['label'] . '($0)';
        $item['insertTextFormat'] = InsertTextFormat::Snippet->value;

        return $item;
    }
}
