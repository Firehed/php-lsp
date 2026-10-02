<?php

declare(strict_types=1);

namespace Firehed\PhpLsp\Tests\EndToEnd;

use Amp\Process\Process;

trait StartsServerTrait
{
    /**
     * The physical path of the fixture project, as the server will see its
     * own working directory.
     */
    private function projectRoot(): string
    {
        $root = realpath(dirname(__DIR__) . '/Fixtures');
        assert($root !== false);

        return $root;
    }

    /**
     * @param string $projectRoot The server takes its project from its working directory.
     */
    private function startServer(string $projectRoot): ServerProcess
    {
        return new ServerProcess(Process::start(
            [PHP_BINARY, dirname(__DIR__, 2) . '/bin/php-lsp'],
            $projectRoot,
        ));
    }
}
