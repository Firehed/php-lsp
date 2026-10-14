<?php

// The fixture project's map after a regeneration that replaced the `Fixtures\`
// prefix with `Regen\`.

$vendorDir = dirname(__DIR__);
$baseDir = dirname($vendorDir);

return array(
    'Psr\\Http\\Message\\' => array($vendorDir . '/psr/http-message/src'),
    'Regen\\' => array($baseDir . '/ComposerRegeneration/src'),
);
