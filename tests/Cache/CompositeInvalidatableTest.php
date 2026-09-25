<?php

declare(strict_types=1);

namespace Firehed\PhpLsp\Tests\Cache;

use Firehed\PhpLsp\Cache\CompositeInvalidatable;
use Firehed\PhpLsp\Cache\InvalidatableInterface;
use PHPUnit\Framework\TestCase;

/**
 * The composite over {@see InvalidatableInterface} (CLAUDE.md Caching/Invalidation):
 * a single fan-out to every cached on-disk holder for one changed path, so the
 * sink holds one invalidatable and the ordered list lives in one place.
 */
final class CompositeInvalidatableTest extends TestCase
{
    public function testInvalidateFansOutToEveryMember(): void
    {
        $uri = 'file:///workspace/src/Changed.php';
        $first = $this->createMock(InvalidatableInterface::class);
        $first->expects($this->once())
            ->method('invalidate')
            ->with($uri);
        $second = $this->createMock(InvalidatableInterface::class);
        $second->expects($this->once())
            ->method('invalidate')
            ->with($uri);

        (new CompositeInvalidatable([$first, $second]))->invalidate($uri);
    }

    public function testInvalidateWithNoMembersIsANoOp(): void
    {
        // A composite with no members is legal; the sink can be wired before any
        // cached backend exists without a null branch.
        (new CompositeInvalidatable([]))->invalidate('file:///anything.php');

        $this->expectNotToPerformAssertions();
    }
}
