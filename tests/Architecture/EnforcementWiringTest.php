<?php

declare(strict_types=1);

namespace Firehed\PhpLsp\Tests\Architecture;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

/**
 * A rule reports nothing when it is not registered, and an allowlist entry
 * grants nothing when its path no longer exists. Both read exactly like a
 * satisfied rule, so nothing but this test tells them apart.
 */
final class EnforcementWiringTest extends TestCase
{
    /**
     * Adding an entry loosens (human only): it declares that the rule decides
     * where it applies by something other than a file path.
     *
     * @var array<class-string, string>
     */
    private const array NOT_PATH_CONFINED = [
        RawInitializeCapabilitiesRule::class => 'confined to a namespace, which no path names',
    ];

    /**
     * @return iterable<string, array{class-string}>
     */
    public static function rules(): iterable
    {
        foreach (self::glob(self::root() . '/tests/Architecture/*Rule.php') as $file) {
            $shortName = basename($file, '.php');
            $className = 'Firehed\PhpLsp\Tests\Architecture\\' . $shortName;
            self::assertTrue(class_exists($className), "{$file} does not declare {$className}");
            yield $shortName => [$className];
        }
    }

    #[DataProvider('rules')]
    public function testRuleIsRegisteredWithPhpstan(string $rule): void
    {
        self::assertStringContainsString(
            "class: {$rule}\n",
            self::config(),
            'an unregistered rule analyses nothing, and reads the same as a satisfied one',
        );
    }

    #[DataProvider('rules')]
    public function testRuleHasItsOwnTest(string $rule): void
    {
        self::assertTrue(
            class_exists($rule . 'Test'),
            'a rule with no RuleTestCase can stop reporting without any test failing',
        );
    }

    #[DataProvider('rules')]
    public function testEveryAllowlistedPathExists(string $rule): void
    {
        $reflection = new ReflectionClass($rule);
        if (array_key_exists($rule, self::NOT_PATH_CONFINED)) {
            self::assertFalse(
                $reflection->hasConstant('ALLOWED_FILES'),
                "{$rule} now confines by path, so drop it from NOT_PATH_CONFINED and let its paths be checked",
            );

            return;
        }

        self::assertTrue(
            $reflection->hasConstant('ALLOWED_FILES'),
            "{$rule} must declare ALLOWED_FILES, or register in NOT_PATH_CONFINED with the reason",
        );
        $allowed = $reflection->getConstant('ALLOWED_FILES');
        self::assertIsArray($allowed);

        foreach ($allowed as $path) {
            self::assertIsString($path);
            self::assertFileExists(
                self::root() . '/' . $path,
                'a stale allowlist entry exempts nothing and hides that the rule now covers the file',
            );
        }
    }

    public function testEveryAllowInPathInTheConfigExists(): void
    {
        preg_match_all('#^\s+- (src/\S+)$#m', self::config(), $matches);
        self::assertNotEmpty($matches[1], 'the allowIn paths must still be readable in this shape');

        foreach (array_unique($matches[1]) as $path) {
            self::assertNotEmpty(
                self::glob(self::root() . '/' . $path),
                "{$path} is named by phpstan.neon but no longer exists",
            );
        }
    }

    /**
     * @return list<string>
     */
    private static function glob(string $pattern): array
    {
        $matches = glob($pattern);

        return $matches === false ? [] : $matches;
    }

    private static function config(): string
    {
        $contents = file_get_contents(self::root() . '/phpstan.neon');
        self::assertIsString($contents);

        return $contents;
    }

    private static function root(): string
    {
        return dirname(__DIR__, 2);
    }
}
