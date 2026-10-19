<?php

declare(strict_types=1);

namespace Firehed\PhpLsp\Tests\EndToEnd\Session;

use DirectoryIterator;
use Firehed\PhpLsp\Handler\DocumentFeatureHandlerInterface;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\TestCase;

/**
 * A feature handler added to the server cannot be asked for by a script until
 * it is a {@see Feature}, so a new handler fails here until scripts can cover it.
 */
#[CoversNothing]
final class FeatureTest extends TestCase
{
    public function testEveryFeatureHandlerIsAFeatureScriptsCanAskFor(): void
    {
        $handlers = self::featureHandlers();
        self::assertNotSame([], $handlers, 'src/Handler must hold feature handlers, or this test passes vacuously');

        foreach ($handlers as $handler) {
            self::assertNotNull(
                Feature::tryFrom($handler::method()),
                "{$handler} answers {$handler::method()}, which scripts must be able to ask for",
            );
        }
    }

    /**
     * @return list<class-string<DocumentFeatureHandlerInterface>>
     */
    private static function featureHandlers(): array
    {
        $handlers = [];
        foreach (new DirectoryIterator(dirname(__DIR__, 3) . '/src/Handler') as $file) {
            if ($file->getExtension() !== 'php') {
                continue;
            }
            $class = 'Firehed\\PhpLsp\\Handler\\' . $file->getBasename('.php');
            if (class_exists($class) && is_a($class, DocumentFeatureHandlerInterface::class, true)) {
                $handlers[] = $class;
            }
        }

        return $handlers;
    }
}
