<?php

declare(strict_types=1);

namespace Fixtures\TypeInference;

class Literals
{
    public function assignments(string $name): void
    {
        $decimal = 1_000;
        $decimal; //jtd:decimal decimal
        $hex = 0x1F;
        $hex; //jtd:hex hex
        $overflow = 9223372036854775808;
        $overflow; //jtd:overflow overflow
        $float = 1.5;
        $float; //jtd:float float
        $single = 'single';
        $single; //jtd:single single
        $interpolated = "hello {$name}";
        $interpolated; //jtd:interpolated interpolated
        $heredoc = <<<EOT
            hello {$name}
            EOT;
        $heredoc; //jtd:heredoc heredoc
        $nowdoc = <<<'EOT'
            hello
            EOT;
        $nowdoc; //jtd:nowdoc nowdoc
        $line = __LINE__;
        $line; //jtd:line line
        $class = __CLASS__;
        $class; //jtd:class class
        $true = true;
        $true; //jtd:true true
        $false = False;
        $false; //jtd:false false
        $null = NULL;
        $null; //jtd:null null
        $qualifiedTrue = \true;
        $qualifiedTrue; //jtd:qualified_true qualifiedTrue
    }

    public function hoverOnTrue(): void
    {
        echo TRUE; //hover:upper_true
    }
}
