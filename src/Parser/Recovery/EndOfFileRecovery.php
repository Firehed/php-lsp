<?php

declare(strict_types=1);

namespace Firehed\PhpLsp\Parser\Recovery;

use Firehed\PhpLsp\Parser\SyntaxSource\CodeParserInterface;
use Firehed\PhpLsp\Parser\SyntaxSource\ParseAttempt;

/**
 * Code typed at the end of a file usually stops mid-construct, and php-parser
 * reads nothing when it reaches the end of the file still recovering from an
 * error. This {@see RecoveryInterface} finishes the code and reads it again.
 * The added text sits past the end of the code, so every position in the code
 * is unchanged, and what it adds is not written.
 */
final class EndOfFileRecovery implements RecoveryInterface
{
    use FinishesCodeTrait;

    public function __construct(
        private readonly CodeParserInterface $parser,
    ) {
    }

    public function recover(string $code, ParseAttempt $attempt): ParseAttempt
    {
        if ($attempt->tree !== [] || $attempt->errors === []) {
            return $attempt;
        }
        $finished = $this->parser->parse($code . self::finish($attempt->tokens));
        if ($finished->tree === []) {
            return $attempt;
        }
        self::place($finished->tree, 0, 0, strlen($code) - 1);
        return $finished;
    }
}
