<?php

declare(strict_types=1);

namespace Firehed\PhpLsp\Tests\Knowledge;

use Firehed\PhpLsp\Knowledge\NullTextSymbolExtractor;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(NullTextSymbolExtractor::class)]
final class NullTextSymbolExtractorTest extends TestCase
{
    public function testExtractProducesNothing(): void
    {
        // The inert default the sink uses when no regex primitive is injected.
        // A test caller that opts into broken-file recovery injects the real one;
        // callers that do not stay tier-3-blind here.
        $extractor = new NullTextSymbolExtractor();
        self::assertSame([], $extractor->extract("<?php\nclass Widget {\n", '/virtual/W.php'));
    }
}
