<?php

declare(strict_types=1);

namespace Firehed\PhpLsp\Parser\Recovery;

use Firehed\PhpLsp\Parser\SyntaxSource\ParseAttempt;

/**
 * The one-route composite for {@see RecoveryInterface}: each step receives the
 * attempt the steps before it left, in order from the most faithful recovery
 * to the last resort.
 */
final class CompositeRecovery implements RecoveryInterface
{
    /** @var list<RecoveryInterface> */
    private readonly array $steps;

    public function __construct(
        EndOfFileRecovery $endOfFile,
        SkeletonRecovery $skeleton,
    ) {
        $this->steps = [$endOfFile, $skeleton];
    }

    public function recover(string $code, ParseAttempt $attempt): ParseAttempt
    {
        foreach ($this->steps as $step) {
            $attempt = $step->recover($code, $attempt);
        }
        return $attempt;
    }
}
