<?php

declare(strict_types=1);

namespace Firehed\PhpLsp\Tests\Document;

use Firehed\PhpLsp\Document\DocumentManagerInterface;
use Firehed\PhpLsp\Document\DocumentSourceInterface;
use Firehed\PhpLsp\Domain\FileUri;
use Firehed\PhpLsp\Tests\BuildsContainerTrait;
use Firehed\PhpLsp\Tests\LoadsFixturesTrait;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\TestCase;

#[CoversNothing]
final class DocumentSourceWiringTest extends TestCase
{
    use BuildsContainerTrait;
    use LoadsFixturesTrait;

    public function testTheWiredSourceReadsWhatTheWiredManagerHolds(): void
    {
        $container = $this->buildContainer();
        $uri = FileUri::fromPath($this->fixturePath('src/Domain/User.php'));
        $buffer = $this->loadFixture('src/Domain/Entity.php');

        $container->get(DocumentManagerInterface::class)->open($uri, 'php', 1, $buffer);

        self::assertSame(
            $buffer,
            $container->get(DocumentSourceInterface::class)->read($uri)?->getContent(),
            'the source and the sync handler must share one buffer store',
        );
    }
}
