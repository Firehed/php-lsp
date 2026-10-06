<?php

declare(strict_types=1);

namespace Firehed\PhpLsp\Tests\EndToEnd\Step;

use Firehed\PhpLsp\Tests\EndToEnd\Expectation\DefinitionExpectationInterface;
use Firehed\PhpLsp\Tests\EndToEnd\Marker\MarkerInterface;
use Firehed\PhpLsp\Tests\EndToEnd\Result\Location;
use Firehed\PhpLsp\Tests\EndToEnd\Session\Feature;
use Firehed\PhpLsp\Tests\EndToEnd\Session\Session;
use PHPUnit\Framework\Assert;
use stdClass;

/**
 * Asks where the symbol at a marker is defined.
 */
final readonly class Definition implements StepInterface
{
    /**
     * @param string $file Path relative to the project root. It need not be open.
     * @param DefinitionExpectationInterface|list<DefinitionExpectationInterface> $expect
     */
    public function __construct(
        private string $file,
        private MarkerInterface $at,
        private DefinitionExpectationInterface|array $expect,
    ) {
    }

    public function run(Session $session): void
    {
        $result = $session->ask(Feature::Definition, $this->file, $this->at)->result;

        // [LSP] textDocument/definition: Location | Location[] | LocationLink[] | null.
        // Links need the client's `linkSupport`, which is not declared.
        $wire = $result instanceof stdClass ? [$result] : ($result ?? []);
        Assert::assertIsList($wire, 'a definition answer is a Location, a list of them, or null');
        $locations = array_map(static fn (mixed $location): Location => Location::fromWire($location, $session), $wire);

        foreach (is_array($this->expect) ? $this->expect : [$this->expect] as $expectation) {
            $expectation->checkDefinition($locations);
        }
    }
}
