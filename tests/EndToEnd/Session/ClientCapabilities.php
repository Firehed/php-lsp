<?php

declare(strict_types=1);

namespace Firehed\PhpLsp\Tests\EndToEnd\Session;

use stdClass;

/**
 * What the client declares in `initialize` ([LSP] ClientCapabilities). Each
 * field is one variation a script needs; anything else is left undeclared.
 */
final readonly class ClientCapabilities
{
    public function __construct(
        private bool $watchedFilesDynamicRegistration = false,
        private bool $markdownHover = false,
        private bool $snippetCompletion = false,
    ) {
    }

    public function toWire(): stdClass
    {
        $capabilities = new stdClass();
        if ($this->watchedFilesDynamicRegistration) {
            $capabilities->workspace = ['didChangeWatchedFiles' => ['dynamicRegistration' => true]];
        }
        $textDocument = [];
        if ($this->markdownHover) {
            $textDocument['hover'] = ['contentFormat' => ['markdown', 'plaintext']];
        }
        if ($this->snippetCompletion) {
            $textDocument['completion'] = ['completionItem' => ['snippetSupport' => true]];
        }
        if ($textDocument !== []) {
            $capabilities->textDocument = $textDocument;
        }

        return $capabilities;
    }
}
