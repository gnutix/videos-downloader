<?php declare(strict_types=1);

namespace App\Domain;

use App\Domain\Collection\Contents;

interface Source
{
    /**
     * @return \App\Domain\Collection\Contents
     */
    public function getContents(): Contents;
}
