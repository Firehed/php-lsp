<?php

declare(strict_types=1);

namespace Firehed\PhpLsp\Tests\EndToEnd\Step;

use Firehed\PhpLsp\Tests\EndToEnd\Expectation\CompletionExpectationInterface;
use Firehed\PhpLsp\Tests\EndToEnd\Marker\MarkerInterface;
use Firehed\PhpLsp\Tests\EndToEnd\Result\CompletionList;
use Firehed\PhpLsp\Tests\EndToEnd\Session\Feature;
use Firehed\PhpLsp\Tests\EndToEnd\Session\Session;

/**
 * Asks what could be typed at a marker.
 */
final readonly class Complete implements StepInterface
{
    /**
     * @param string $file Path relative to the project root. It need not be open.
     * @param CompletionExpectationInterface|list<CompletionExpectationInterface> $expect
     */
    public function __construct(
        private string $file,
        private MarkerInterface $at,
        private CompletionExpectationInterface|array $expect,
    ) {
    }

    public function run(Session $session): void
    {
        $completions = CompletionList::fromWire($session->ask(Feature::Completion, $this->file, $this->at)->result);

        foreach (is_array($this->expect) ? $this->expect : [$this->expect] as $expectation) {
            $expectation->checkCompletion($completions);
        }
    }
}
