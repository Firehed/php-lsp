<?php

declare(strict_types=1);

namespace Firehed\PhpLsp\Knowledge;

use Firehed\PhpLsp\Domain\ClassInfo;
use Firehed\PhpLsp\Domain\ConstantInfo;
use Firehed\PhpLsp\Domain\FunctionInfo;

/**
 * The write seam the {@see DocumentSymbolSink} holds for open-document lookup
 * state: the two operations a document lifecycle event needs — register the
 * document's declared symbols, or drop them — with no read surface.
 *
 * The three typed lists match PHP's three symbol namespaces, so the store fills
 * one typed map per kind without a route from (name, kind) to a concrete info
 * type (RFC 1 §4.11, one route per fact).
 */
interface DocumentSymbolStoreInterface
{
    public function removeDocument(string $uri): void;

    /**
     * @param list<ClassInfo> $classes
     * @param list<ConstantInfo> $constants
     * @param list<FunctionInfo> $functions
     */
    public function updateDocument(
        string $uri,
        array $classes,
        array $constants,
        array $functions,
    ): void;
}
