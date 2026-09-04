<?php

declare(strict_types=1);

namespace Firehed\PhpLsp\Tests\Architecture;

/**
 * One row of the one-route ledger.
 *
 * A family row names an interface; its implementations are derived from `src/`,
 * the composite among them must be named `Composite<Interface>`, and the whole family
 * must share one namespace named for the interface. A transitional row names
 * concrete route classes that have no interface yet, and the holders that may
 * name them.
 *
 * For either shape, a file that is neither a root nor the named class itself must
 * not name an implementation or route class. Every `*Pending` field records a
 * condition that fails today together with the manifest step that clears it; the
 * row asserts the condition really does fail, then skips, so the entry leaves with
 * the fix and a condition that clears early fails the test.
 */
final class Fact
{
    /**
     * @param ?class-string $interface
     * @param list<class-string> $ingredients Route classes of a transitional row.
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
    public static function transitional(
        string $name,
        array $ingredients,
        array $holders,
        array $roots = [],
        array $pending = [],
    ): self {
        return new self($name, null, $ingredients, $holders, $roots, $pending, null, null);
    }
}
