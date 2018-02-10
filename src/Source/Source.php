<?php declare(strict_types=1);

namespace App\Source;

use App\Domain\Collection;

interface Source
{
    /**
     * @return \App\Domain\Content[]|\App\Domain\Collection
     */
    public function getContents(): Collection;
}
