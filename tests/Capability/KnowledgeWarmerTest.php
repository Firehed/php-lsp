<?php

declare(strict_types=1);

namespace Firehed\PhpLsp\Tests\Capability;

use Firehed\PhpLsp\Cache\Warmable;
use Firehed\PhpLsp\Capability\KnowledgeWarmer;
use Firehed\PhpLsp\Capability\SessionCapabilities;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(KnowledgeWarmer::class)]
final class KnowledgeWarmerTest extends TestCase
{
    public function testWarmsTheKnowledgeTierOnInitialized(): void
    {
        $knowledge = $this->createMock(Warmable::class);
        $knowledge->expects($this->once())->method('warm');

        $warmer = new KnowledgeWarmer($knowledge);
        $warmer->onInitialized(new SessionCapabilities());
    }

    public function testWarmsRegardlessOfWhatTheClientDeclared(): void
    {
        // There is no capability for a client to declare here: warming derives the
        // server's own on-disk state and produces no protocol traffic, so a minimal
        // client gets it just the same.
        $knowledge = $this->createMock(Warmable::class);
        $knowledge->expects($this->once())->method('warm');

        $warmer = new KnowledgeWarmer($knowledge);
        $warmer->onInitialized(new SessionCapabilities(watchedFilesDynamicRegistration: false));
    }
}
