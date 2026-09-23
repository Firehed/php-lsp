<?php

declare(strict_types=1);

namespace Firehed\PhpLsp\Tests\Knowledge;

use Firehed\PhpLsp\Knowledge\BuildsInfoFromDeclarationsTrait;

/**
 * Concrete host so tests can exercise the trait's construction logic directly,
 * without standing up a full backend.
 */
final class InfoFromDeclarationsTraitHost
{
    use BuildsInfoFromDeclarationsTrait;
}
