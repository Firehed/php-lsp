<?php

declare(strict_types=1);

namespace Firehed\PhpLsp\Tests\EndToEnd;

use Amp\Process\Process;

trait StartsServerTrait
{
    /**
     * The physical path of a project, as the server will see its own working
     * directory.
     *
     * @param string $project Path from the repository root.
     */
    private function projectRoot(string $project): string
    {
        $root = realpath(dirname(__DIR__, 2) . '/' . $project);
        assert($root !== false, "No such project: {$project}");

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
