<?php

declare(strict_types=1);

namespace Firehed\PhpLsp\Knowledge;

use Firehed\PhpLsp\Domain\NameKind;
use Firehed\PhpLsp\Domain\QualifiedName;

/**
 * Resolves a qualified name to the file that declares it.
 *
 * The kind is a parameter because {@see QualifiedName} is kind-neutral: `Foo\bar`
 * names a different symbol as a function than as a constant, and only the syntactic
 * position the caller read it from can say which.
 */
interface SymbolLocator
{
    /**
     * @return ?string Absolute path to the file declaring $name, or null when this
     *         locator cannot reach a declaration of it
     */
    public function locate(QualifiedName $name, NameKind $kind): ?string;
}
