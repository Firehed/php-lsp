<?php

declare(strict_types=1);

namespace Firehed\PhpLsp\Tests\EndToEnd\Step;

use Firehed\PhpLsp\Tests\EndToEnd\Expectation\HoverExpectationInterface;
use Firehed\PhpLsp\Tests\EndToEnd\Marker\MarkerInterface;
use Firehed\PhpLsp\Tests\EndToEnd\Result\HoverContent;
use Firehed\PhpLsp\Tests\EndToEnd\Session\Feature;
use Firehed\PhpLsp\Tests\EndToEnd\Session\Session;

/**
 * Asks what the symbol at a marker is.
 */
final readonly class Hover implements StepInterface
{
    /**
     * @param string $file Path relative to the project root. It need not be open.
     * @param HoverExpectationInterface|list<HoverExpectationInterface> $expect
     */
    public function __construct(
        private string $file,
        private MarkerInterface $at,
        private HoverExpectationInterface|array $expect,
    ) {
    }

    public function run(Session $session): void
    {
        $content = HoverContent::fromWire($session->ask(Feature::Hover, $this->file, $this->at)->result);

        foreach (is_array($this->expect) ? $this->expect : [$this->expect] as $expectation) {
            $expectation->checkHover($content);
        }
    }
}
