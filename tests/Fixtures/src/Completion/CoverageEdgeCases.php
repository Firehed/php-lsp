<?php

declare(strict_types=1);

namespace Fixtures\Completion;

class CoverageEdgeCases
{
    public function testKeywordParenNotCall(): void
    {
        // if( is not a function call - should not find call context
        if (/*|keyword_paren*/true) {
        }
    }

    public function testFullyQualifiedNew(): void
    {
        // FQN class name in new expression
        new \Fixtures\Domain\User(/*|fqn_new*/
    }

    public function testNamedArgTracking(): void
    {
        // Named arg should be tracked in usedNames
        $this->methodWithNamed(name: 'value', /*|after_named_arg*/
    }

    public function testMemberAccessInsideMethodArgs(): void
    {
        // Cursor inside method call args - getMemberAccessContext should return null
        $this->methodWithNamed(/*|inside_method_args*/'value');
    }

    public function testMemberAccessInsideStaticArgs(): void
    {
        // Cursor inside static call args - getMemberAccessContext should return null
        NamedArguments::staticWithParams(/*|inside_static_args*/'value');
    }

    private function methodWithNamed(string $name, int $count = 0): void
    {
    }
}
