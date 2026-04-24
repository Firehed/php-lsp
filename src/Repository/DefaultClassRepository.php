<?php

declare(strict_types=1);

namespace Firehed\PhpLsp\Repository;

use Firehed\PhpLsp\Document\TextDocument;
use Firehed\PhpLsp\Domain\ClassInfo;
use Firehed\PhpLsp\Domain\ClassName;
use Firehed\PhpLsp\Parser\ParserService;
use Firehed\PhpLsp\Utility\ClassFinder;
use ReflectionClass;
use ReflectionException;

final class DefaultClassRepository implements ClassRepository
{
    /** @var array<string, ClassInfo> Lowercase FQN -> ClassInfo cache */
    private array $cache = [];

    /** @var array<string, list<ClassInfo>> URI -> Classes in document */
    private array $documentClasses = [];

    /** @var array<string, string> Lowercase FQN -> URI for open document classes */
    private array $documentIndex = [];

    public function __construct(
        private readonly ClassInfoFactory $factory,
        private readonly ClassLocator $locator,
        private readonly ParserService $parser,
    ) {
    }

    public function get(ClassName $name): ?ClassInfo
    {
        $key = $this->normalizeKey($name->fqn);

        // Check cache first for previously resolved classes
        if (array_key_exists($key, $this->cache)) {
            return $this->cache[$key];
        }

        // Open documents take priority - updateDocument() clears cache entries
        // for its classes, so we only reach here for classes not in open docs
        if (array_key_exists($key, $this->documentIndex)) {
            $uri = $this->documentIndex[$key];
            foreach ($this->documentClasses[$uri] as $classInfo) {
                if ($this->normalizeKey($classInfo->name->fqn) === $key) {
                    return $classInfo;
                }
            }
        }

        // Try to locate and parse from filesystem
        $classInfo = $this->locateAndParse($name);
        if ($classInfo !== null) {
            $this->cache[$key] = $classInfo;
            return $classInfo;
        }

        // Fall back to reflection for built-in/autoloaded classes
        $classInfo = $this->fromReflection($name);
        if ($classInfo !== null) {
            $this->cache[$key] = $classInfo;
            return $classInfo;
        }

        return null;
    }

    /**
     * @param list<ClassInfo> $classes
     */
    public function updateDocument(string $uri, array $classes): void
    {
        $this->removeDocument($uri);

        $this->documentClasses[$uri] = $classes;

        foreach ($classes as $classInfo) {
            $key = $this->normalizeKey($classInfo->name->fqn);
            $this->documentIndex[$key] = $uri;

            // Invalidate cache so open document version takes precedence
            unset($this->cache[$key]);
        }
    }

    public function removeDocument(string $uri): void
    {
        if (!array_key_exists($uri, $this->documentClasses)) {
            return;
        }

        foreach ($this->documentClasses[$uri] as $classInfo) {
            $key = $this->normalizeKey($classInfo->name->fqn);
            unset($this->documentIndex[$key]);
        }

        unset($this->documentClasses[$uri]);
    }

    private function locateAndParse(ClassName $name): ?ClassInfo
    {
        $filePath = $this->locator->locate($name);
        if ($filePath === null || !is_readable($filePath)) {
            return null;
        }

        $content = file_get_contents($filePath);
        if ($content === false) {
            return null;
        }

        $uri = 'file://' . $filePath;
        $document = new TextDocument($uri, 'php', 0, $content);
        $ast = $this->parser->parse($document);
        if ($ast === null) {
            return null;
        }

        $node = ClassFinder::findInAst($name->fqn, $ast);
        if ($node === null) {
            return null;
        }

        return $this->factory->fromAstNode($node, $uri);
    }

    private function fromReflection(ClassName $name): ?ClassInfo
    {
        try {
            $reflection = new ReflectionClass($name->fqn);
            return $this->factory->fromReflection($reflection);
        } catch (ReflectionException) {
            return null;
        }
    }

    public function isSubclassOf(ClassName $class, ClassName $potentialParent): bool
    {
        $classInfo = $this->get($class);
        if ($classInfo === null) {
            return false;
        }

        $targetKey = $this->normalizeKey($potentialParent->fqn);
        $visited = [$this->normalizeKey($class->fqn) => true];

        return $this->checkInheritance($classInfo, $targetKey, $visited);
    }

    /**
     * @param array<string, true> $visited
     */
    private function checkInheritance(ClassInfo $classInfo, string $targetKey, array &$visited): bool
    {
        // Check parent
        if ($classInfo->parent !== null) {
            $parentKey = $this->normalizeKey($classInfo->parent->fqn);
            if ($parentKey === $targetKey) {
                return true;
            }
            if (!array_key_exists($parentKey, $visited)) {
                $visited[$parentKey] = true;
                $parentInfo = $this->get($classInfo->parent);
                if ($parentInfo !== null && $this->checkInheritance($parentInfo, $targetKey, $visited)) {
                    return true;
                }
            }
        }

        // Check interfaces
        foreach ($classInfo->interfaces as $interface) {
            $interfaceKey = $this->normalizeKey($interface->fqn);
            if ($interfaceKey === $targetKey) {
                return true;
            }
            if (!array_key_exists($interfaceKey, $visited)) {
                $visited[$interfaceKey] = true;
                $interfaceInfo = $this->get($interface);
                if ($interfaceInfo !== null && $this->checkInheritance($interfaceInfo, $targetKey, $visited)) {
                    return true;
                }
            }
        }

        return false;
    }

    private function normalizeKey(string $fqn): string
    {
        return strtolower(ltrim($fqn, '\\'));
    }
}
