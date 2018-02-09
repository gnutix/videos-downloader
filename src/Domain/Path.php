<?php declare(strict_types=1);

namespace App\Domain;

use Doctrine\Common\Collections\Criteria;

final class Path extends Collection
{
    /**
     * @return string
     */
    public function __toString(): string
    {
        return implode(
            DIRECTORY_SEPARATOR,
            $this->matching(Criteria::create()->orderBy(array('priority' => Criteria::ASC)))
                ->map(function (PathPart $pathPart) {
                    return $pathPart->getPath();
                })
                ->toArray()
        );
    }
}
