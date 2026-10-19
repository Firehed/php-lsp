<?php

declare(strict_types=1);

namespace Firehed\PhpLsp\Tests\Parser;

use Firehed\PhpLsp\Parser\ArgumentList;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(ArgumentList::class)]
final class ArgumentListTest extends TestCase
{
    public function testConstruction(): void
    {
        $list = new ArgumentList(opensAt: 4, separators: [7, 12]);

        self::assertSame(4, $list->opensAt, 'the `(` is kept as given');
        self::assertSame([7, 12], $list->separators, 'the commas are kept as given, in order');
    }
}
