<?php declare(strict_types=1);

namespace App\Platform;

use App\Domain\Collection;
use App\Domain\PathPart;
use App\UI\UserInterface;

interface Platform
{
    /**
     * @param \App\UI\UserInterface $ui
     * @param array $options
     * @param bool $dryRun
     */
    public function __construct(UserInterface $ui, array $options, bool $dryRun = false);

    /**
     * @param \App\Domain\Content[]|\App\Domain\Collection $contents
     * @param \App\Domain\PathPart $rootPathPart
     */
    public function synchronizeContents(Collection $contents, PathPart $rootPathPart);
}
