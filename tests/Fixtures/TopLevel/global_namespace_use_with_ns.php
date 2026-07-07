<?php
namespace App;

use GlobalClass;

// Test global namespace class import within a namespace
// Bug: strrpos returns false, causing import map to have 'lobalClass' => 'GlobalClass'
// When resolving 'GlobalClass', it falls back to namespace prefix: App\GlobalClass (wrong!)
GlobalClass::/*|global_ns_use*/
