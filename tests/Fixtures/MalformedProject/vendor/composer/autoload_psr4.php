<?php

// A deliberately malformed stand-in for Composer's generated autoload map, used
// to prove that reading it validates its shape rather than trusting it. Not a
// real project: it exists only to be read as data.

return [
    'Valid\\' => ['/tmp/valid'],
    'Malformed\\' => 'not-a-list-of-directories',
    12345 => ['/tmp/numeric-prefix'],
];
