<?php

declare(strict_types=1);

namespace Firehed\PhpLsp\Tests\EndToEnd\Expectation;

use Firehed\PhpLsp\Tests\EndToEnd\Result\SignatureHelpResult;

/**
 * Something a signature help answer must satisfy. A failure is a PHPUnit
 * assertion failure.
 */
interface SignatureHelpExpectationInterface
{
    public function checkSignatureHelp(SignatureHelpResult $result): void;
}
