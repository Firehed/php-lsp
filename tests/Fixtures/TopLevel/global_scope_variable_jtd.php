<?php

declare(strict_types=1);

// #301 acceptance: JTD on a variable in global scope resolves to the
// nearest preceding binding.
$greeting = 'hello';
$greeting; //jtd:global_assignment_usage greeting
