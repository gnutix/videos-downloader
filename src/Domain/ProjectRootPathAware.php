<?php declare(strict_types=1);

namespace App\Domain;

interface ProjectRootPathAware
{
    /**
     * @param Path $projectRootPath
     *
     * @return void
     */
    public function setProjectRootPath(Path $projectRootPath): void;
}
