<?php

declare(strict_types=1);

namespace Firehed\PhpLsp\Index;

/**
 * The set of constants built into PHP and its loaded extensions, excluding
 * user-defined constants. The single home for the "is this constant internal?"
 * check, which PHP provides for functions (ReflectionFunction::isInternal) but
 * not for constants.
 */
final class InternalConstantSet
{
    /** @var array<string, true>|null Lazily built on first query */
    private ?array $set = null;

    public function contains(string $fqn): bool
    {
        return array_key_exists($fqn, $this->all());
    }

    /**
     * @return array<string, true> FQN -> true for membership checks
     */
    public function all(): array
    {
        if ($this->set === null) {
            $this->set = $this->build();
        }

        return $this->set;
    }

    /**
     * @return array<string, true>
     */
    private function build(): array
    {
        $set = [];
        $constants = get_defined_constants(categorize: true);
        unset($constants['user']);
        foreach ($constants as $category) {
            foreach (array_keys($category) as $name) {
                $set[$name] = true;
            }
        }

        return $set;
    }
}
