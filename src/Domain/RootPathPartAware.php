<?php declare(strict_types=1);

namespace App\Domain;

interface RootPathPartAware
{
    /**
     * @param PathPart $rootPathPart
     *
     * @return void
     */
    public function setRootPathPart(PathPart $rootPathPart): void;
}
