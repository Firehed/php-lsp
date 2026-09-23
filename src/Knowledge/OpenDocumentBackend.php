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
use Firehed\PhpLsp\Domain\QualifiedName;
use Firehed\PhpLsp\Domain\SymbolInfoInterface;
use Firehed\PhpLsp\Index\CatalogSymbol;
use Firehed\PhpLsp\Index\NamespaceContents;
use Firehed\PhpLsp\Index\Symbol;

/**
 * The highest-precedence {@see SymbolBackendInterface}: the documents the editor has open
 * (RFC 1 §5.3). Its answers override every on-disk backend, so a user's unsaved
 * edits are honored — including edits to a vendored file opened in the editor.
 *
 * Open documents change on every keystroke and are never cached (RFC 1 §5.3). Each
 * of PHP's three symbol namespaces has its own typed map, so a class-like lookup
 * never sees a function of the same name, and a stored info's own type matches
 * the typed lookup that returns it — no runtime narrow at the read boundary.
 */
final class OpenDocumentBackend implements SymbolBackendInterface, SymbolSourceInterface, DocumentSymbolStoreInterface
{
    /** @var array<string, ClassInfo> Normalized class-like key -> info */
    private array $classesByKey = [];

    /** @var array<string, list<string>> URI -> normalized class-like keys it declares */
    private array $classKeysByUri = [];

    /** @var array<string, ConstantInfo> Normalized constant key -> info */
    private array $constantsByKey = [];

    /** @var array<string, list<string>> URI -> normalized constant keys it declares */
    private array $constantKeysByUri = [];

    /** @var array<string, FunctionInfo> Normalized function key -> info */
    private array $functionsByKey = [];

    /** @var array<string, list<string>> URI -> normalized function keys it declares */
    private array $functionKeysByUri = [];

    public function childrenOf(NamespaceName $namespace): NamespaceContents
    {
        $targetKey = $namespace->normalize();
        $childNamespaces = [];
        $symbols = [];

        foreach ($this->classesByKey as $info) {
            self::collectInNamespace(
                $info->name->qualifiedName->fullyQualifiedName(),
                NameKind::ClassLike,
                $namespace,
                $targetKey,
                $symbols,
                $childNamespaces,
            );
        }
        foreach ($this->constantsByKey as $info) {
            self::collectInNamespace(
                $info->name->qualifiedName->fullyQualifiedName(),
                NameKind::Constant,
                $namespace,
                $targetKey,
                $symbols,
                $childNamespaces,
            );
        }
        foreach ($this->functionsByKey as $info) {
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

    public function lookup(QualifiedName $name, NameKind $kind): ?SymbolInfoInterface
    {
        if ($kind->isClassLike()) {
            return $this->classesByKey[$kind->keyFor($name)] ?? null;
        }
        if ($kind->isConstant()) {
            return $this->constantsByKey[$kind->keyFor($name)] ?? null;
        }
        return $this->functionsByKey[$kind->keyFor($name)] ?? null;
    }

    public function lookupClassLike(ClasslikeName $name): ?ClassInfo
    {
        return $this->classesByKey[NameKind::ClassLike->keyFor($name->qualifiedName)] ?? null;
    }

    public function lookupConstant(ConstantName $name): ?ConstantInfo
    {
        return $this->constantsByKey[$name->kind->keyFor($name->qualifiedName)] ?? null;
    }

    public function lookupFunction(FunctionName $name): ?FunctionInfo
    {
        return $this->functionsByKey[$name->kind->keyFor($name->qualifiedName)] ?? null;
    }

    public function removeDocument(string $uri): void
    {
        foreach ($this->classKeysByUri[$uri] ?? [] as $key) {
            unset($this->classesByKey[$key]);
        }
        foreach ($this->constantKeysByUri[$uri] ?? [] as $key) {
            unset($this->constantsByKey[$key]);
        }
        foreach ($this->functionKeysByUri[$uri] ?? [] as $key) {
            unset($this->functionsByKey[$key]);
        }
        unset(
            $this->classKeysByUri[$uri],
            $this->constantKeysByUri[$uri],
            $this->functionKeysByUri[$uri],
        );
    }

    /**
     * @return list<Symbol>
     */
    public function search(string $prefix, NameKind $kind): array
    {
        if ($kind->isClassLike()) {
            return $this->searchIn($this->classesByKey, $this->classKeysByUri, $prefix, $kind);
        }
        if ($kind->isConstant()) {
            return $this->searchIn($this->constantsByKey, $this->constantKeysByUri, $prefix, $kind);
        }
        return $this->searchIn($this->functionsByKey, $this->functionKeysByUri, $prefix, $kind);
    }

    public function updateDocument(
        string $uri,
        array $classes,
        array $constants,
        array $functions,
    ): void {
        $this->removeDocument($uri);

        $classKeys = [];
        foreach ($classes as $info) {
            $key = NameKind::ClassLike->keyFor($info->name->qualifiedName);
            $this->classesByKey[$key] = $info;
            $classKeys[] = $key;
        }
        $this->classKeysByUri[$uri] = $classKeys;

        $constantKeys = [];
        foreach ($constants as $info) {
            $key = NameKind::Constant->keyFor($info->name->qualifiedName);
            $this->constantsByKey[$key] = $info;
            $constantKeys[] = $key;
        }
        $this->constantKeysByUri[$uri] = $constantKeys;

        $functionKeys = [];
        foreach ($functions as $info) {
            $key = NameKind::Function_->keyFor($info->name->qualifiedName);
            $this->functionsByKey[$key] = $info;
            $functionKeys[] = $key;
        }
        $this->functionKeysByUri[$uri] = $functionKeys;
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
     * @param array<string, ClassInfo|ConstantInfo|FunctionInfo> $infosByKey
     * @param array<string, list<string>> $keysByUri
     * @return list<Symbol>
     */
    private function searchIn(
        array $infosByKey,
        array $keysByUri,
        string $prefix,
        NameKind $nameKind,
    ): array {
        $uriByKey = [];
        foreach ($keysByUri as $uri => $keys) {
            foreach ($keys as $key) {
                $uriByKey[$key] = $uri;
            }
        }

        $results = [];
        foreach ($infosByKey as $key => $info) {
            $shortName = $info->name->qualifiedName->shortName;
            if (!PrefixMatcher::matches($shortName, $prefix)) {
                continue;
            }
            $uri = $uriByKey[$key] ?? '';
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
