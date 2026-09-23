<?php

declare(strict_types=1);

namespace Firehed\PhpLsp\Knowledge;

use Firehed\PhpLsp\Domain\ClassInfo;
use Firehed\PhpLsp\Domain\ClasslikeName;
use Firehed\PhpLsp\Domain\ConstantInfo;
use Firehed\PhpLsp\Domain\ConstantName;
use Firehed\PhpLsp\Domain\FunctionInfo;
use Firehed\PhpLsp\Domain\FunctionName;
use Firehed\PhpLsp\Domain\Location;
use Firehed\PhpLsp\Domain\NameKind;
use Firehed\PhpLsp\Domain\NamespaceName;
use Firehed\PhpLsp\Domain\PrefixMatcher;
use Firehed\PhpLsp\Index\CatalogSymbol;
use Firehed\PhpLsp\Index\NamespaceContents;
use Firehed\PhpLsp\Index\Symbol;

/**
 * The highest-precedence {@see SymbolSourceInterface}: the documents the editor
 * has open (RFC 1 §5.3). Its answers override every on-disk backend, so a user's
 * unsaved edits are honored — including edits to a vendored file opened in the
 * editor.
 *
 * Open documents change on every keystroke and are never cached (RFC 1 §5.3). Each
 * of PHP's three symbol namespaces has its own typed map, so a class-like lookup
 * never sees a function of the same name, and a stored info's own type matches
 * the typed lookup that returns it — no runtime narrow at the read boundary.
 * Every stored entry is a `[uri, info]` tuple: the URI that declared the info is
 * held once, next to the info itself, so no shadow index of URI-to-key or
 * key-to-URI is required.
 */
final class OpenDocumentBackend implements SymbolSourceInterface, DocumentSymbolStoreInterface
{
    /** @var array<string, array{string, ClassInfo}> Normalized class-like key -> [declaring URI, info] */
    private array $classesByKey = [];

    /** @var array<string, array{string, ConstantInfo}> Normalized constant key -> [declaring URI, info] */
    private array $constantsByKey = [];

    /** @var array<string, array{string, FunctionInfo}> Normalized function key -> [declaring URI, info] */
    private array $functionsByKey = [];

    public function childrenOf(NamespaceName $namespace): NamespaceContents
    {
        $targetKey = $namespace->normalize();
        $childNamespaces = [];
        $symbols = [];

        foreach ($this->classesByKey as [, $info]) {
            self::collectInNamespace(
                $info->name->qualifiedName->fullyQualifiedName(),
                NameKind::ClassLike,
                $namespace,
                $targetKey,
                $symbols,
                $childNamespaces,
            );
        }
        foreach ($this->constantsByKey as [, $info]) {
            self::collectInNamespace(
                $info->name->qualifiedName->fullyQualifiedName(),
                NameKind::Constant,
                $namespace,
                $targetKey,
                $symbols,
                $childNamespaces,
            );
        }
        foreach ($this->functionsByKey as [, $info]) {
            self::collectInNamespace(
                $info->name->qualifiedName->fullyQualifiedName(),
                NameKind::Function_,
                $namespace,
                $targetKey,
                $symbols,
                $childNamespaces,
            );
        }

        return new NamespaceContents(array_values($childNamespaces), $symbols);
    }

    public function lookupClassLike(ClasslikeName $name): ?ClassInfo
    {
        return $this->classesByKey[NameKind::ClassLike->keyFor($name->qualifiedName)][1] ?? null;
    }

    public function lookupConstant(ConstantName $name): ?ConstantInfo
    {
        return $this->constantsByKey[$name->kind->keyFor($name->qualifiedName)][1] ?? null;
    }

    public function lookupFunction(FunctionName $name): ?FunctionInfo
    {
        return $this->functionsByKey[$name->kind->keyFor($name->qualifiedName)][1] ?? null;
    }

    public function removeDocument(string $uri): void
    {
        foreach ($this->classesByKey as $key => [$storedUri]) {
            if ($storedUri === $uri) {
                unset($this->classesByKey[$key]);
            }
        }
        foreach ($this->constantsByKey as $key => [$storedUri]) {
            if ($storedUri === $uri) {
                unset($this->constantsByKey[$key]);
            }
        }
        foreach ($this->functionsByKey as $key => [$storedUri]) {
            if ($storedUri === $uri) {
                unset($this->functionsByKey[$key]);
            }
        }
    }

    /**
     * @return list<Symbol>
     */
    public function searchClassLikes(string $prefix): array
    {
        return $this->searchIn($this->classesByKey, $prefix, NameKind::ClassLike);
    }

    /**
     * @return list<Symbol>
     */
    public function searchConstants(string $prefix): array
    {
        return $this->searchIn($this->constantsByKey, $prefix, NameKind::Constant);
    }

    /**
     * @return list<Symbol>
     */
    public function searchFunctions(string $prefix): array
    {
        return $this->searchIn($this->functionsByKey, $prefix, NameKind::Function_);
    }

    public function updateDocument(
        string $uri,
        array $classes,
        array $constants,
        array $functions,
    ): void {
        $this->removeDocument($uri);

        foreach ($classes as $info) {
            $this->classesByKey[NameKind::ClassLike->keyFor($info->name->qualifiedName)] = [$uri, $info];
        }
        foreach ($constants as $info) {
            $this->constantsByKey[NameKind::Constant->keyFor($info->name->qualifiedName)] = [$uri, $info];
        }
        foreach ($functions as $info) {
            $this->functionsByKey[NameKind::Function_->keyFor($info->name->qualifiedName)] = [$uri, $info];
        }
    }

    /**
     * @param array<string, string> $childNamespaces
     * @param list<CatalogSymbol> $symbols
     */
    private static function collectInNamespace(
        string $fqn,
        NameKind $kind,
        NamespaceName $namespace,
        string $targetKey,
        array &$symbols,
        array &$childNamespaces,
    ): void {
        $ns = new NamespaceName(NamespaceName::namespaceOf($fqn));
        if ($ns->normalize() === $targetKey) {
            $symbols[] = new CatalogSymbol($fqn, $kind);
            return;
        }
        $below = $ns->relativeTo($namespace);
        if ($below === null) {
            return;
        }
        $child = NamespaceName::join($namespace->path, NamespaceName::firstSegment($below));
        $childNamespaces[(new NamespaceName($child))->normalize()] ??= $child;
    }

    /**
     * @param array<string, array{string, ClassInfo|ConstantInfo|FunctionInfo}> $infosByKey
     * @return list<Symbol>
     */
    private function searchIn(array $infosByKey, string $prefix, NameKind $nameKind): array
    {
        $results = [];
        foreach ($infosByKey as [$uri, $info]) {
            $shortName = $info->name->qualifiedName->shortName;
            if (!PrefixMatcher::matches($shortName, $prefix)) {
                continue;
            }
            $results[] = new Symbol(
                name: $shortName,
                fullyQualifiedName: $info->name->qualifiedName->fullyQualifiedName(),
                kind: $info->symbolKind(),
                location: new Location($uri, 0, 0, 0, 0),
                nameKind: $nameKind,
            );
        }
        return $results;
    }
}
