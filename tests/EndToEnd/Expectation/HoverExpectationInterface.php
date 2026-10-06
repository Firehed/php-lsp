<?php

declare(strict_types=1);

namespace Firehed\PhpLsp\Tests\EndToEnd\Expectation;

use Firehed\PhpLsp\Tests\EndToEnd\Result\HoverContent;

/**
 * Something a hover answer must satisfy. A failure is a PHPUnit assertion
 * failure.
 */
interface HoverExpectationInterface
{
    /**
     * @param HoverContent|null $content Null when the server has no answer.
     */
    public function checkHover(?HoverContent $content): void;
}
