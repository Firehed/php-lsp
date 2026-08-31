<?php

declare(strict_types=1);

namespace Firehed\PhpLsp\Tests\Domain;

use Firehed\PhpLsp\Domain\ClassName;
use Firehed\PhpLsp\Domain\TraitAlias;
use Firehed\PhpLsp\Domain\Visibility;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(TraitAlias::class)]
final class TraitAliasTest extends TestCase
{
    public function testCarriesEveryFieldItIsConstructedWith(): void
    {
        $alias = new TraitAlias(
            trait: new ClassName('Some\\Trait'),
            method: 'original',
            newName: 'renamed',
            newVisibility: Visibility::Protected,
        );

        self::assertSame('Some\\Trait', $alias->trait?->fqn, 'source trait is retained');
        self::assertSame('original', $alias->method, 'source method name is retained');
        self::assertSame('renamed', $alias->newName, 'new exposed name is retained');
        self::assertSame(Visibility::Protected, $alias->newVisibility, 'new visibility is retained');
    }

    public function testAllowsNullTraitForImplicitTraitResolution(): void
    {
        $alias = new TraitAlias(
            trait: null,
            method: 'ambiguous',
            newName: null,
            newVisibility: Visibility::Private,
        );

        self::assertNull($alias->trait, 'trait may be null when PHP resolves it at lookup');
        self::assertNull($alias->newName, 'new name may be null when the alias only changes visibility');
    }
}
