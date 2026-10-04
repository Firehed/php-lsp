<?php

declare(strict_types=1);

namespace Firehed\PhpLsp\Tests\EndToEnd;

use Firehed\PhpLsp\Tests\Parity\GoldenCodec;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Runs each script in `scripts/` against a real server and compares the
 * conversation to the transcript recorded beside it. Recapture a transcript
 * with `UPDATE_GOLDENS=1` and review the diff.
 */
#[CoversNothing]
final class ScriptTest extends TestCase
{
    use StartsServerTrait;

    private const string SCRIPTS = __DIR__ . '/scripts';

    #[DataProvider('scripts')]
    public function testConversationMatchesItsTranscript(string $name): void
    {
        // Loaded in its own scope: a script may define variables of its own.
        $script = (static fn(string $path): mixed => require $path)(self::SCRIPTS . "/{$name}.php");
        self::assertInstanceOf(Script::class, $script, 'a script file returns a Script');

        $projectRoot = $this->projectRoot($script->project);
        $server = $this->startServer($projectRoot);
        $client = new LspClient($server);
        $session = new Session($client, $projectRoot);

        $session->start($script->capabilities);
        foreach ($script->steps as $step) {
            $step->run($session);
        }
        $session->end();

        self::assertSame(0, $server->waitForExit(), 'the session ends cleanly');
        self::assertSame('', $server->stderr(), 'the server writes nothing to stderr');

        // The server reports paths under its physical working directory, which
        // differs per machine.
        $recorded = str_replace($projectRoot, '{project}', GoldenCodec::encode($client->transcript));
        $transcript = self::SCRIPTS . "/{$name}.json";
        if (getenv('UPDATE_GOLDENS') === '1') {
            file_put_contents($transcript, $recorded);
        }
        self::assertJsonStringEqualsJsonFile(
            $transcript,
            $recorded,
            "Script '{$name}' does not match its transcript. If the change is intended, "
                . 'record it with UPDATE_GOLDENS=1 and review the diff.',
        );
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function scripts(): iterable
    {
        $files = glob(self::SCRIPTS . '/*.php');
        assert($files !== false);

        foreach ($files as $file) {
            $name = basename($file, '.php');
            yield $name => [$name];
        }
    }
}
