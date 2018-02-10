<?php declare(strict_types=1);

namespace App\Platform;

use App\Domain\Collection;
use App\Domain\PathPart;

interface Platform
{
    /**
     * @param \App\Domain\Content[]|\App\Domain\Collection $contents
     * @param \App\Domain\PathPart $rootPathPart
     */
    public function synchronizeContents(Collection $contents, PathPart $rootPathPart);
}
