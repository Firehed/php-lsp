<?php

declare(strict_types=1);

namespace Firehed\PhpLsp\Tests\Completion;

use Firehed\PhpLsp\Completion\CompletionItemFactory;
use Firehed\PhpLsp\Completion\CompletionItemKind;
use Firehed\PhpLsp\Completion\NamespaceCandidates;
use Firehed\PhpLsp\Index\NamespaceCatalog;
use Firehed\PhpLsp\Index\NamespaceContents;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(NamespaceCandidates::class)]
#[CoversClass(CompletionItemFactory::class)]
class NamespaceCandidatesTest extends TestCase
{
    /**
     * @param list<string> $childNamespaces
     */
    private static function catalogWith(array $childNamespaces): NamespaceCatalog
    {
        return new class ($childNamespaces) implements NamespaceCatalog {
            /**
             * @param list<string> $childNamespaces
             */
            public function __construct(private readonly array $childNamespaces)
            {
            }

            public function childrenOf(string $namespace): NamespaceContents
            {
                return new NamespaceContents($this->childNamespaces, []);
            }
        };
    }

    public function testOffersChildNamespacesAsModuleNodes(): void
    {
        $candidates = new NamespaceCandidates(self::catalogWith(['Psr\Http', 'Psr\Log']));

        $items = $candidates->find('Psr', '', 0, 0);

        $byLabel = array_column($items, 'detail', 'label');
        self::assertSame(
            'Psr\Http',
            $byLabel['Http\\'] ?? null,
            'A child namespace is a navigable node whose label is the next segment plus a separator',
        );
        self::assertSame('Psr\Log', $byLabel['Log\\'] ?? null, 'Each child of the namespace is offered');
        foreach ($items as $item) {
            self::assertSame(
                CompletionItemKind::Module->value,
                $item['kind'] ?? null,
                'A namespace is a Module, not a class',
            );
        }
    }

    public function testFiltersChildrenByTheSegmentPrefix(): void
    {
        $candidates = new NamespaceCandidates(self::catalogWith(['Psr\Http', 'Psr\Log']));

        $items = $candidates->find('Psr', 'Ht', 0, 2);

        self::assertSame(
            ['Http\\'],
            array_column($items, 'label'),
            'Only the child whose next segment matches the typed prefix is offered',
        );
    }
}
