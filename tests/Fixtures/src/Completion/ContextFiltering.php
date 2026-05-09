<?php

declare(strict_types=1);

namespace Fixtures\Completion;

class ContextFiltering
{
    public function inComment(): void
    {
        $localVar = 'test';
        // /*|in_comment*/
    }

    public function inHeredoc(): void
    {
        $localVar = 'test';
        $html = <<<HTML
        <div>/*|in_heredoc*/</div>
        HTML;
    }
}
