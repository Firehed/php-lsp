<?php

declare(strict_types=1);

namespace Firehed\PhpLsp\Tests\Resolution;

use Firehed\PhpLsp\Domain\ClassCandidateFilter;
use Firehed\PhpLsp\Domain\NameKind;
use Firehed\PhpLsp\Resolution\NameContext;
use Firehed\PhpLsp\Resolution\NameQuery;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(NameQuery::class)]
final class NameQueryTest extends TestCase
{
    public function testConstruction(): void
    {
        $context = new NameContext('App');

        $query = new NameQuery([NameKind::ClassLike], ClassCandidateFilter::Instantiable, $context, absolute: true);

        self::assertSame([NameKind::ClassLike], $query->kinds, 'the kinds of name admitted');
        self::assertSame(ClassCandidateFilter::Instantiable, $query->filter, 'which class-likes are admitted');
        self::assertSame($context, $query->context, 'how a name resolves');
        self::assertTrue($query->absolute, 'a name resolves from the global namespace');
    }
}
