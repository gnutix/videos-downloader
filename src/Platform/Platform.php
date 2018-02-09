<?php declare(strict_types=1);

namespace App\Platform;

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
     * @param \App\Domain\Content[] $contents
     * @param string $rootPathPart
     */
    public function downloadContents(array $contents, string $rootPathPart);
}
