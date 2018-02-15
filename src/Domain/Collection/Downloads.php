<?php declare(strict_types=1);

namespace App\Domain\Collection;

use App\Domain\Download;
use Doctrine\Common\Collections\Criteria;

/**
 * @method static __construct(Download[] $elements)
 * @method Download[] toArray()
 * @method Download first()
 * @method Download last()
 * @method Download next()
 * @method Download current()
 * @method bool removeElement(Download $element)
 * @method bool contains(Download $element)
 * @method mixed indexOf(Download $element)
 * @method Download[] getValues()
 * @method void set($key, Download $element)
 * @method bool add(Download $element)
 * @method \ArrayIterator|Download[] getIterator()
 * @method Download[]|Downloads map(\Closure $p)
 * @method Download[]|Downloads filter(\Closure $p)
 * @method Downloads[] partition(\Closure $p)
 * @method Download[]|Downloads matching(Criteria $criteria)
 */
abstract class Downloads extends Collection
{
}
