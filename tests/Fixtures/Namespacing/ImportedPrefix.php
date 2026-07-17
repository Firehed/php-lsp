<?php

declare(strict_types=1);

namespace App;

use Fixtures\Model\Env;

/**
 * Completion of an imported name that is also a namespace prefix (#339). Each
 * incomplete reference lives in its own method so parser recovery is not confused
 * by multiple broken statements.
 */
class ImportedPrefix
{
    public function bare(): void
    {
        new Env/*|imported_bare*/
    }

    public function slash(): void
    {
        new Env\/*|imported_slash*/
    }

    public function partial(): void
    {
        new Env\R/*|imported_partial*/
    }

    public function noMatch(): void
    {
        new Env\Zz/*|imported_no_match*/
    }

    public function interfaceAfterNew(): void
    {
        new Env\H/*|imported_iface_new*/
    }

    public function interfaceAsType(Env\H/*|imported_iface_type*/ $handler): void
    {
    }

    public function unrelated(): void
    {
        new Other\R/*|imported_unrelated*/
    }
}
