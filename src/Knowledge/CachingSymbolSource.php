<?php

declare(strict_types=1);

namespace Firehed\PhpLsp\Knowledge;

use Firehed\PhpLsp\Cache\CacheKey;
use Firehed\PhpLsp\Cache\InvalidatableInterface;
use Firehed\PhpLsp\Domain\ClassInfo;
use Firehed\PhpLsp\Domain\ClasslikeName;
use Firehed\PhpLsp\Domain\ComposerAutoloadMap;
use Firehed\PhpLsp\Domain\ConstantInfo;
use Firehed\PhpLsp\Domain\ConstantName;
use Firehed\PhpLsp\Domain\FileUri;
use Firehed\PhpLsp\Domain\FunctionInfo;
use Firehed\PhpLsp\Domain\FunctionName;
use Firehed\PhpLsp\Domain\NameKind;
use Firehed\PhpLsp\Domain\NamespaceContents;
use Firehed\PhpLsp\Domain\NamespaceName;
use Firehed\PhpLsp\Domain\NamespaceOwnedNameInterface;
use Firehed\PhpLsp\Domain\Symbol;
use Firehed\PhpLsp\Domain\SymbolInfoInterface;
use Psr\SimpleCache\CacheInterface;

/**
 * Remembers what a {@see SymbolSourceInterface} answered, for sources that read
 * disk or reflection. Applied in wiring; never around open documents, which
 * change on every keystroke.
 *
 * A lookup is remembered by name and kind, hit or miss. A hit is dropped when the
 * file that declares it is invalidated. A miss names no file, and a namespace
 * listing is keyed by namespace rather than by file, so both are dropped on any
 * invalidation. Search is passed through: the prefix changes on every keystroke,
 * so a remembered answer is rarely asked for twice.
 */
final class CachingSymbolSource implements SymbolSourceInterface, InvalidatableInterface
{
    /** A remembered miss is stored as null, so absence needs a marker of its own. */
    private const string UNCACHED = 'uncached';

    /** @var array<string, list<string>> Declaring path -> the keys of the hits it declares */
    private array $keysByPath = [];

    /** @var list<string> */
    private array $listingKeys = [];

    /** @var list<string> */
    private array $missKeys = [];

    private ?ComposerAutoloadMap $mapAtLastCheck = null;

    public function __construct(
        private readonly SymbolSourceInterface $inner,
        private readonly CacheInterface $cache,
        private readonly ?ComposerAutoloadMapReader $mapReader = null,
    ) {
        $this->mapAtLastCheck = $this->mapReader?->current();
    }

    public function childrenOf(NamespaceName $namespace): NamespaceContents
    {
        $key = CacheKey::from('children|' . $namespace->normalize());

        $cached = $this->cache->get($key);
        if ($cached instanceof NamespaceContents) {
            return $cached;
        }

        $contents = $this->inner->childrenOf($namespace);
        $this->cache->set($key, $contents);
        $this->listingKeys[] = $key;

        return $contents;
    }

    public function invalidate(string $uri): void
    {
        $currentMap = $this->mapReader?->current();
        if ($currentMap !== null && $currentMap !== $this->mapAtLastCheck) {
            $this->keysByPath = [];
            $this->listingKeys = [];
            $this->missKeys = [];
            $this->cache->clear();
            $this->mapAtLastCheck = $currentMap;

            return;
        }

        $path = FileUri::toPath($uri);
        $keys = [...($this->keysByPath[$path] ?? []), ...$this->listingKeys, ...$this->missKeys];
        unset($this->keysByPath[$path]);
        $this->listingKeys = [];
        $this->missKeys = [];

        $this->cache->deleteMultiple($keys);
    }

    public function lookupClassLike(ClasslikeName $name): ?ClassInfo
    {
        return $this->remember(
            $name,
            fn(): ?ClassInfo => $this->inner->lookupClassLike($name),
            static fn(ClassInfo $info): ?string => $info->file,
        );
    }

    public function lookupConstant(ConstantName $name): ?ConstantInfo
    {
        return $this->remember(
            $name,
            fn(): ?ConstantInfo => $this->inner->lookupConstant($name),
            static fn(ConstantInfo $info): ?string => $info->file,
        );
    }

    public function lookupFunction(FunctionName $name): ?FunctionInfo
    {
        return $this->remember(
            $name,
            fn(): ?FunctionInfo => $this->inner->lookupFunction($name),
            static fn(FunctionInfo $info): ?string => $info->file,
        );
    }

    /**
     * @return list<Symbol>
     */
    public function search(string $prefix, NameKind $kind): array
    {
        return $this->inner->search($prefix, $kind);
    }

    /**
     * The key carries the kind, and each kind resolves to one concrete info type,
     * so a remembered hit is the type the caller resolved.
     *
     * @template T of SymbolInfoInterface
     * @param callable(): ?T $resolve Consulted only when nothing is remembered
     * @param callable(T): ?string $declaringFile
     * @return ?T
     */
    private function remember(
        NamespaceOwnedNameInterface $name,
        callable $resolve,
        callable $declaringFile,
    ): ?SymbolInfoInterface {
        $key = CacheKey::from($name->kind->keyFor($name->qualifiedName));

        $cached = $this->cache->get($key, self::UNCACHED);
        if ($cached === null) {
            return null;
        }
        if ($cached !== self::UNCACHED) {
            assert($cached instanceof SymbolInfoInterface);
            /** @var T */
            return $cached;
        }

        $info = $resolve();
        $this->cache->set($key, $info);
        if ($info === null) {
            $this->missKeys[] = $key;

            return null;
        }

        $file = $declaringFile($info);
        if ($file !== null) {
            $this->keysByPath[FileUri::toPath($file)][] = $key;
        }

        return $info;
    }
}
