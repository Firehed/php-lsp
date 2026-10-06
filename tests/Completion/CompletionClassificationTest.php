<?php

declare(strict_types=1);

namespace Firehed\PhpLsp\Tests\Completion;

use Firehed\PhpLsp\Completion\CompletionClassification;
use Firehed\PhpLsp\Completion\CompletionKind;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(CompletionClassification::class)]
final class CompletionClassificationTest extends TestCase
{
    public function testKeepsTheKindAndPrefix(): void
    {
        $classification = new CompletionClassification(CompletionKind::Variable, 'na');

        self::assertSame(CompletionKind::Variable, $classification->kind, 'the kind is kept');
        self::assertSame('na', $classification->prefix, 'the prefix is kept');
    }
}
