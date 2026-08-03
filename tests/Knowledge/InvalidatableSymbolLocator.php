<?php

declare(strict_types=1);

namespace Firehed\PhpLsp\Tests\Knowledge;

use Firehed\PhpLsp\Cache\Invalidatable;
use Firehed\PhpLsp\Knowledge\SymbolLocator;

/**
 * A locator that also takes part in external-change invalidation — the shape
 * {@see \Firehed\PhpLsp\Index\ComposerSymbolLocator} has, and the one
 * {@see \Firehed\PhpLsp\Knowledge\FilesystemBackend::invalidate()} fans out to.
 *
 * Named rather than mocked as an intersection so the mock carries both types for
 * static analysis as well as at runtime.
 */
interface InvalidatableSymbolLocator extends SymbolLocator, Invalidatable
{
}
