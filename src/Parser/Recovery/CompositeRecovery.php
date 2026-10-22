<?php

declare(strict_types=1);

namespace Firehed\PhpLsp\Parser\Recovery;

use Firehed\PhpLsp\Parser\SyntaxSource\ParseAttempt;

/**
 * The one-route composite for {@see RecoveryInterface}: each step receives the
 * attempt the steps before it left. A tree comes from the parser, else from
 * finishing the file, else from the skeleton; then what that tree dropped is
 * grafted back into it.
 */
final class CompositeRecovery implements RecoveryInterface
{
    /** @var list<RecoveryInterface> */
    private readonly array $steps;

    public function __construct(
        EndOfFileRecovery $endOfFile,
        SkeletonRecovery $skeleton,
        GapGraftingRecovery $gaps,
    ) {
        $this->steps = [$endOfFile, $skeleton, $gaps];
    }

    public function recover(string $code, ParseAttempt $attempt): ParseAttempt
    {
        foreach ($this->steps as $step) {
            $attempt = $step->recover($code, $attempt);
        }
        return $attempt;
    }
}
