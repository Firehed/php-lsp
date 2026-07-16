<?php

declare(strict_types=1);

namespace Firehed\PhpLsp\Tests\Completion;

use Firehed\PhpLsp\Completion\ClassCandidateFilter;
use Firehed\PhpLsp\Domain\ClassName;
use Firehed\PhpLsp\Resolution\CodeResolver;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

#[CoversClass(ClassCandidateFilter::class)]
class ClassCandidateFilterTest extends TestCase
{
    public function testAnyAcceptsWithoutResolving(): void
    {
        self::assertTrue(
            ClassCandidateFilter::Any->accepts(new ClassName(\stdClass::class), self::createStub(CodeResolver::class)),
            'Any position accepts every class-like without consulting the resolver',
        );
    }

    /**
     * Each filter defers to exactly one resolver predicate: a stub in which only
     * that method returns true accepts, which fails if the filter consulted a
     * different one.
     */
    #[DataProvider('provideFilterPredicates')]
    public function testFilterDefersToItsPredicate(ClassCandidateFilter $filter, string $method): void
    {
        $resolver = self::createStub(CodeResolver::class);
        $resolver->method($method)->willReturn(true);

        self::assertTrue($filter->accepts(new ClassName(\stdClass::class), $resolver));
    }

    /**
     * @codeCoverageIgnore
     * @return iterable<string, array{ClassCandidateFilter, string}>
     */
    public static function provideFilterPredicates(): iterable
    {
        yield 'instantiable' => [ClassCandidateFilter::Instantiable, 'isInstantiable'];
        yield 'type hint' => [ClassCandidateFilter::TypeHint, 'isValidTypeHint'];
        yield 'interface' => [ClassCandidateFilter::Interface_, 'isInterface'];
        yield 'extendable class' => [ClassCandidateFilter::ExtendableClass, 'isExtendableClass'];
        yield 'throwable' => [ClassCandidateFilter::Throwable, 'isThrowable'];
        yield 'attribute' => [ClassCandidateFilter::Attribute, 'isAttribute'];
    }
}
