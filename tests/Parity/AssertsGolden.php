<?php

declare(strict_types=1);

namespace Firehed\PhpLsp\Tests\Parity;

/**
 * Compares a captured surface output against a stored per-surface golden.
 *
 * A golden is captured once (with `UPDATE_GOLDENS=1`), spot-audited, committed,
 * and diffed on every run thereafter. Any divergence is a failure: a
 * behavior-preserving migration must reproduce the golden byte for byte, and a
 * step that intends to change one surface recaptures only that surface's golden
 * while the others stay frozen. See docs/architecture/0002-execution-plan.md,
 * Step P.
 */
trait AssertsGolden
{
    /**
     * When true, {@see assertGoldenMatches} rewrites the golden instead of
     * diffing it. Driven by `UPDATE_GOLDENS=1`; the self-test sets it directly.
     */
    protected bool $updateGoldens = false;

    /**
     * @param array<array-key, mixed> $captured
     */
    protected function assertGoldenMatches(string $name, array $captured): void
    {
        $path = $this->goldenDir() . '/' . $name . '.json';
        $encoded = GoldenCodec::encode($captured);

        if ($this->updateGoldens || getenv('UPDATE_GOLDENS') === '1') {
            file_put_contents($path, $encoded);
        }

        self::assertFileExists(
            $path,
            "No golden for surface '{$name}'. Capture it with UPDATE_GOLDENS=1 and spot-audit the result.",
        );
        self::assertSame(
            file_get_contents($path),
            $encoded,
            "Surface '{$name}' diverged from its golden. If this change is intended for this "
                . 'surface, recapture it with UPDATE_GOLDENS=1 and spot-audit the diff; otherwise '
                . 'the migration is not behavior-preserving.',
        );
    }

    protected function goldenDir(): string
    {
        return __DIR__ . '/goldens';
    }
}
