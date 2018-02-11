<?php declare(strict_types=1);

namespace App\Domain\Collection;

use App\Domain\PathPart;
use Doctrine\Common\Collections\Criteria;

/**
 * This is a Collection of PathPart, which we can name a "Path" (beware: it is not a Collection of "Paths").
 *
 * @method PathPart first()
 * @method PathPart last()
 * @method PathPart next()
 * @method PathPart current()
 * @method PathPart get($key)
 * @method PathPart[] toArray()
 * @method PathPart[] getValues()
 * @method PathPart[]|Path map(\Closure $p)
 * @method PathPart[]|Path filter(\Closure $p)
 * @method PathPart[]|Path slice($offset, $length = null)
 * @method PathPart[]|Path matching(Criteria $criteria)
 * @method Path[] partition(\Closure $p)
 */
final class Path extends Collection
{
    /**
     * {@inheritdoc}
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
