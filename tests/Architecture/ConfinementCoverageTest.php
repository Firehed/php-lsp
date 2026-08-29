<?php

declare(strict_types=1);

namespace Firehed\PhpLsp\Tests\Architecture;

use Firehed\PhpLsp\Domain\Type;
use Firehed\PhpLsp\Domain\ResolvedSymbol;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

/**
 * The §8.1 rules name what they confine in hand-written lists, so each rule is
 * a consumer of the axis it guards: add an implementation and forget the list,
 * and analysis still passes. An omission and a satisfied rule are the same
 * observation, so only this test can tell them apart.
 *
 * The implementation lists are derived here, so a new `Type` or
 * `ResolvedSymbol` fails until it is confined. The kind-enum list cannot be
 * derived — no signature separates `SymbolKind` from `Visibility` — so it is a
 * registry: an enum is confined, or registered here with the reason it is not
 * a kind, or the test fails.
 */
final class ConfinementCoverageTest extends TestCase
{
    /**
     * Adding an entry loosens (human only): it declares the enum is not a kind.
     *
     * @var array<class-string, string>
     */
    private const array NOT_A_SYMBOL_KIND = [
        \Firehed\PhpLsp\Completion\ClassCandidateFilter::class => 'position intent, not a resolved kind',
        \Firehed\PhpLsp\Completion\CompletionContext::class => 'position intent',
        \Firehed\PhpLsp\Completion\CompletionKind::class => 'position intent',
        \Firehed\PhpLsp\Completion\InsertTextFormat::class => 'LSP wire value',
        \Firehed\PhpLsp\Completion\KeywordGroup::class => 'keyword vocabulary',
        \Firehed\PhpLsp\Completion\TypeHintContext::class => 'position intent',
        \Firehed\PhpLsp\Domain\LateBindingKeyword::class => 'the self/static/parent keywords',
        \Firehed\PhpLsp\Domain\NameCase::class => 'a case rule, applied rather than branched on',
        \Firehed\PhpLsp\Domain\Visibility::class => 'an ordering',
        \Firehed\PhpLsp\Protocol\MarkupKind::class => 'LSP wire value',
        \Firehed\PhpLsp\Protocol\PositionEncoding::class => 'LSP wire value',
        \Firehed\PhpLsp\Resolution\ReferenceKind::class => 'how a name is reachable from the cursor',
    ];

    public function testEveryEnumIsConfinedOrRegistered(): void
    {
        $confined = self::ruleConstant(KindBranchRule::class, 'CONFINED_KIND_ENUMS');

        $unregistered = [];
        foreach (self::sourceClassNames() as $className) {
            if (!enum_exists($className)) {
                continue;
            }
            if (in_array($className, $confined, true) || array_key_exists($className, self::NOT_A_SYMBOL_KIND)) {
                continue;
            }
            $unregistered[] = $className;
        }

        self::assertSame(
            [],
            $unregistered,
            'each enum is confined by KindBranchRule or registered in NOT_A_SYMBOL_KIND with its reason',
        );
    }

    public function testEveryResolvedSymbolImplementationIsConfined(): void
    {
        self::assertSame(
            [],
            array_values(array_diff(
                self::implementationsOf(ResolvedSymbol::class),
                self::ruleConstant(KindInspectionRule::class, 'CONFINED_RESOLVED_IMPLS'),
            )),
            'an unconfined ResolvedSymbol implementation may be instanceof-inspected anywhere (RFC 1 §4.5)',
        );
    }

    public function testEveryTypeImplementationIsConfinedAgainstInspection(): void
    {
        self::assertSame(
            [],
            array_values(array_diff(
                self::implementationsOf(Type::class),
                self::ruleConstant(KindInspectionRule::class, 'CONFINED_TYPE_IMPLS'),
            )),
            'an unconfined Type implementation may be instanceof-inspected anywhere (RFC 1 §4.5)',
        );
    }

    public function testEveryTypeImplementationIsConfinedAgainstConstruction(): void
    {
        self::assertSame(
            [],
            array_values(array_diff(
                self::implementationsOf(Type::class),
                self::ruleConstant(TypeConstructionRule::class, 'CONFINED_TYPES'),
            )),
            'an unconfined Type implementation may be constructed outside TypeFactory (RFC 1 §4.6)',
        );
    }

    /**
     * @param class-string $interface
     *
     * @return list<class-string>
     */
    private static function implementationsOf(string $interface): array
    {
        $implementations = [];
        foreach (self::sourceClassNames() as $className) {
            if (!class_exists($className)) {
                continue;
            }
            $reflection = new ReflectionClass($className);
            if (!$reflection->isAbstract() && $reflection->implementsInterface($interface)) {
                $implementations[] = $className;
            }
        }

        return $implementations;
    }

    /**
     * Every name `src/` declares, by PSR-4 arithmetic on its path.
     *
     * @return list<class-string>
     */
    private static function sourceClassNames(): array
    {
        $root = dirname(__DIR__, 2) . '/src';
        $files = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS),
        );

        $names = [];
        foreach ($files as $file) {
            assert($file instanceof \SplFileInfo);
            if ($file->getExtension() !== 'php') {
                continue;
            }
            $relative = substr($file->getPathname(), strlen($root) + 1, -strlen('.php'));
            $className = 'Firehed\PhpLsp\\' . str_replace('/', '\\', $relative);
            self::assertTrue(
                class_exists($className) || interface_exists($className)
                    || enum_exists($className) || trait_exists($className),
                "{$file->getPathname()} does not declare {$className}",
            );
            $names[] = $className;
        }
        sort($names);

        return $names;
    }

    /**
     * @param class-string $rule
     *
     * @return list<string>
     */
    private static function ruleConstant(string $rule, string $name): array
    {
        $constant = (new ReflectionClass($rule))->getReflectionConstant($name);
        self::assertNotFalse($constant, "{$rule} must still declare {$name}, or this test guards nothing");
        $list = $constant->getValue();
        assert(is_array($list));
        self::assertContainsOnlyString($list);

        return array_values($list);
    }
}
