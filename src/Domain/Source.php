<?php declare(strict_types=1);

namespace App\Domain;

interface Source
{
    /**
     * @return \App\Domain\Contents
     */
    public function getContents(): Contents;
}
