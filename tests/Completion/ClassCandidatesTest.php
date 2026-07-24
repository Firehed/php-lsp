<?php

declare(strict_types=1);

namespace Firehed\PhpLsp\Tests\Completion;

use Firehed\PhpLsp\Capability\SessionCapabilities;
use Firehed\PhpLsp\Capability\SessionCapabilitiesProvider;
use Firehed\PhpLsp\Completion\ClassCandidateFilter;
use Firehed\PhpLsp\Completion\ClassCandidates;
use Firehed\PhpLsp\Completion\CompletionItemFactory;
use Firehed\PhpLsp\Document\TextDocument;
use Firehed\PhpLsp\Index\SymbolIndex;
use Firehed\PhpLsp\Resolution\CodeResolver;
use Firehed\PhpLsp\Resolution\NameContext;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(ClassCandidates::class)]
#[CoversClass(CompletionItemFactory::class)]
class ClassCandidatesTest extends TestCase
{
    public function testReplaceRangeMeasuresAMultibytePrefixInCodeUnits(): void
    {
        $resolver = self::createStub(CodeResolver::class);
        $resolver->method('getNameContext')->willReturn(new NameContext(''));
        // An imported class whose short name carries a multibyte character.
        $resolver->method('getImports')->willReturn(['Café' => 'App\\Café']);

        $candidates = new ClassCandidates(new SymbolIndex(), $resolver, self::utf16Capabilities());
        $document = new TextDocument('file:///test.php', 'php', 1, '<?php Café');

        // "Café" is four codepoints — four UTF-16 units — but five UTF-8 bytes; the
        // cursor sits at wire column 4 after typing it.
        $items = $candidates->find('Café', $document, 0, 4, ClassCandidateFilter::Any);

        self::assertCount(1, $items);
        self::assertSame(
            ['start' => ['line' => 0, 'character' => 0], 'end' => ['line' => 0, 'character' => 4]],
            $items[0]['textEdit']['range'] ?? null,
            'The replace range sizes the typed prefix in code units, not bytes (RFC 1 §4.9)',
        );
    }

    private static function utf16Capabilities(): SessionCapabilitiesProvider
    {
        $capabilities = self::createStub(SessionCapabilitiesProvider::class);
        $capabilities->method('getSessionCapabilities')->willReturn(new SessionCapabilities());
        return $capabilities;
    }
}
