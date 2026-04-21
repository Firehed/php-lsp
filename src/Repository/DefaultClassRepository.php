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
    /** @var array<string, ClassInfo> FQN -> ClassInfo cache */
    private array $cache = [];

    /** @var array<string, list<ClassInfo>> URI -> Classes in document */
    private array $documentClasses = [];

    /** @var array<string, string> FQN -> URI for open document classes */
    private array $documentIndex = [];

    public function __construct(
        private readonly ClassInfoFactory $factory,
        private readonly ClassLocator $locator,
        private readonly ParserService $parser,
    ) {
    }

    public function get(ClassName $name): ?ClassInfo
    {
        $fqn = ltrim($name->fqn, '\\');

        if (array_key_exists($fqn, $this->cache)) {
            return $this->cache[$fqn];
        }

        if (array_key_exists($fqn, $this->documentIndex)) {
            $uri = $this->documentIndex[$fqn];
            foreach ($this->documentClasses[$uri] as $classInfo) {
                if (strcasecmp($classInfo->name->fqn, $fqn) === 0) {
                    return $classInfo;
                }
            }
        }

        $classInfo = $this->locateAndParse($name);
        if ($classInfo !== null) {
            $this->cache[$fqn] = $classInfo;
            return $classInfo;
        }

        $classInfo = $this->fromReflection($name);
        if ($classInfo !== null) {
            $this->cache[$fqn] = $classInfo;
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
            $fqn = ltrim($classInfo->name->fqn, '\\');
            $this->documentIndex[$fqn] = $uri;

            unset($this->cache[$fqn]);
        }
    }

    public function removeDocument(string $uri): void
    {
        if (!array_key_exists($uri, $this->documentClasses)) {
            return;
        }

        foreach ($this->documentClasses[$uri] as $classInfo) {
            $fqn = ltrim($classInfo->name->fqn, '\\');
            unset($this->documentIndex[$fqn]);
        }

        unset($this->documentClasses[$uri]);
    }

    private function locateAndParse(ClassName $name): ?ClassInfo
    {
        $filePath = $this->locator->locate($name);
        if ($filePath === null) {
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
}
