<?php

declare(strict_types=1);

namespace Firehed\PhpLsp\Tests\EndToEnd\Step;

use Firehed\PhpLsp\Tests\EndToEnd\Expectation\SignatureHelpExpectationInterface;
use Firehed\PhpLsp\Tests\EndToEnd\Marker\MarkerInterface;
use Firehed\PhpLsp\Tests\EndToEnd\Result\SignatureHelpResult;
use Firehed\PhpLsp\Tests\EndToEnd\Session\Feature;
use Firehed\PhpLsp\Tests\EndToEnd\Session\Session;

/**
 * Asks which signature applies to the call around a marker.
 */
final readonly class SignatureHelp implements StepInterface
{
    /**
     * @param string $file Path relative to the project root. It need not be open.
     * @param SignatureHelpExpectationInterface|list<SignatureHelpExpectationInterface> $expect
     */
    public function __construct(
        private string $file,
        private MarkerInterface $at,
        private SignatureHelpExpectationInterface|array $expect,
    ) {
    }

    public function run(Session $session): void
    {
        $result = SignatureHelpResult::fromWire($session->ask(Feature::SignatureHelp, $this->file, $this->at)->result);

        foreach (is_array($this->expect) ? $this->expect : [$this->expect] as $expectation) {
            $expectation->checkSignatureHelp($result);
        }
    }
}
