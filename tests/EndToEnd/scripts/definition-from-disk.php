<?php

declare(strict_types=1);

use Firehed\PhpLsp\Tests\EndToEnd\Ask;
use Firehed\PhpLsp\Tests\EndToEnd\Feature;
use Firehed\PhpLsp\Tests\EndToEnd\Script;

// Nothing is opened: a server answers for a document whether or not the
// editor has it open ([LSP] textDocument/didOpen).
return new Script([
    new Ask(Feature::Definition, 'src/Services/ApiController.php', 'attr_class'),
]);
