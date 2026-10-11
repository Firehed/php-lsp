<?php

declare(strict_types=1);

// PostToolUse hook: after every 15 file edits in a session, asks the agent to
// run the "Staying on track" check in AGENTS.md before continuing.

const EVERY = 15;

$input = json_decode((string) file_get_contents('php://stdin'), true);
$session = is_array($input) && is_string($input['session_id'] ?? null) ? $input['session_id'] : 'unknown';
$counter = sys_get_temp_dir() . '/php-lsp-edits-' . preg_replace('/[^A-Za-z0-9_-]/', '', $session);

$count = (file_exists($counter) ? (int) file_get_contents($counter) : 0) + 1;
file_put_contents($counter, (string) $count);

if ($count % EVERY !== 0) {
    exit(0);
}

echo json_encode([
    'hookSpecificOutput' => [
        'hookEventName' => 'PostToolUse',
        'additionalContext' => "This session has made {$count} file edits. Before the next change, run the "
            . '"Staying on track" check in AGENTS.md against the original request, and stop and report '
            . 'to the user if any answer is yes.',
    ],
]);
