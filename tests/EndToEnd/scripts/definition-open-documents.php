<?php

declare(strict_types=1);

use Firehed\PhpLsp\Tests\EndToEnd\Ask;
use Firehed\PhpLsp\Tests\EndToEnd\Feature;
use Firehed\PhpLsp\Tests\EndToEnd\Open;
use Firehed\PhpLsp\Tests\EndToEnd\Script;

return new Script(
    project: 'tests/Fixtures',
    steps: [
        new Open('src/Attributes/Route.php'),
        new Open('src/Services/ApiController.php'),
        new Ask(Feature::Definition, 'src/Services/ApiController.php', 'attr_class'),
    ],
);
