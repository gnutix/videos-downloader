<?php declare(strict_types=1);

namespace App\Domain;

use App\Domain\Collection\Contents;

interface Platform
{
    /**
     * @param \App\Domain\Collection\Contents $contents
     * @param \App\Domain\PathPart $rootPathPart
     */
    public function synchronizeContents(Contents $contents, PathPart $rootPathPart);
}
