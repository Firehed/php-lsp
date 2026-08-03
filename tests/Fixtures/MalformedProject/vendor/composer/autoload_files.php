<?php

// A deliberately malformed stand-in for Composer's generated autoload.files map,
// used to prove that reading it validates its shape rather than trusting it. Not a
// real project: it exists only to be read as data.

return [
    'a1b2c3' => '/tmp/valid-helpers.php',
    'd4e5f6' => ['not-a-path'],
    'g7h8i9' => 12345,
];
