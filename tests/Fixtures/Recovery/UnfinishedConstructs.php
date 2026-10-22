<?php

namespace Fixtures\Recovery;

class UnfinishedConstructs
{
    public function separator(): void
    {
        new Foo\/*|after_separator*/
    }

    public function loop(): void
    {
        for ($i = 0; $this?->/*|in_for*/
    }

    public function choice(): void
    {
        match (Foo::/*|in_match*/
    }
}
