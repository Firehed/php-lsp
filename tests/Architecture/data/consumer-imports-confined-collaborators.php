<?php

declare(strict_types=1);

namespace Firehed\PhpLsp\Completion;

use Firehed\PhpLsp\Document\TextDocument;
use Firehed\PhpLsp\Index\ComposerAutoloadMap;
use Firehed\PhpLsp\Index\NamespaceCatalog;
use Firehed\PhpLsp\Index\SymbolIndex;
use Firehed\PhpLsp\Repository\ClassRepository;
use Firehed\PhpLsp\Repository\FunctionRepository;
use ReflectionClass;

/**
 * A consumer reaching for the symbol-discovery collaborators directly, which RFC 1
 * §4.2 confines to a SymbolSource/SymbolSink backend. TextDocument is unrelated to
 * discovery and passes; FunctionRepository is the function-path exemption Step 3
 * removes (Plan 0002 §5.5, §5.7) and also passes.
 */
final class ConsumerImportsConfinedCollaborators
{
    public function __construct(
        private readonly TextDocument $document,
        private readonly ComposerAutoloadMap $autoload,
        private readonly NamespaceCatalog $catalog,
        private readonly SymbolIndex $index,
        private readonly ClassRepository $classes,
        private readonly FunctionRepository $functions,
    ) {
    }

    public function reflect(string $class): ReflectionClass
    {
        return new ReflectionClass($class);
    }
}
