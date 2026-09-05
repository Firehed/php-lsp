<?php

declare(strict_types=1);

namespace Firehed\PhpLsp\Tests\Architecture;

/**
 * One row of the one-route ledger.
 *
 * A family row names an interface; its implementations are derived from `src/`,
 * the composite among them must be named `Composite<Interface>`, and the whole
 * family must share one namespace named for the interface. A confinement row
 * names concrete classes — a vendor parser, a helper, a store — and the holders
 * that may name them; it is the shape for a route that has no interface, whether
 * for one more step or for good.
 *
 * For either shape, a file that is neither a root nor a holder must not name a
 * route class other than itself. Every `*Pending` field records a condition that
 * fails today together with the manifest step that clears it; the row asserts the
 * condition really does fail, then skips, so the entry leaves with the fix and a
 * condition that clears early fails the test.
 */
final class Fact
{
    /**
     * @param ?class-string $interface
     * @param list<class-string> $ingredients Route classes of a confinement row.
     * @param list<class-string> $holders Classes that may name the route classes.
     * @param list<class-string> $roots Composition roots that may name any of them.
     * @param array<string, string> $pending Repo-relative file path => step.
     * @param ?string $compositePending Step that adds `Composite<Interface>`.
     * @param ?string $layoutPending Step that moves the family into its namespace.
     */
    private function __construct(
        public string $name,
        public ?string $interface,
        public array $ingredients,
        public array $holders,
        public array $roots,
        public array $pending,
        public ?string $compositePending,
        public ?string $layoutPending,
    ) {
    }

    /**
     * @param class-string $interface
     * @param list<class-string> $roots
     * @param array<string, string> $pending
     */
    public static function family(
        string $name,
        string $interface,
        array $roots,
        array $pending = [],
        ?string $compositePending = null,
        ?string $layoutPending = null,
    ): self {
        return new self($name, $interface, [], [], $roots, $pending, $compositePending, $layoutPending);
    }

    /**
     * @param list<class-string> $ingredients
     * @param list<class-string> $holders
     * @param list<class-string> $roots
     * @param array<string, string> $pending
     */
    public static function confined(
        string $name,
        array $ingredients,
        array $holders,
        array $roots = [],
        array $pending = [],
    ): self {
        return new self($name, null, $ingredients, $holders, $roots, $pending, null, null);
    }
}
