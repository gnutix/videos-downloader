<?php declare(strict_types=1);

namespace App\Platform\YouTube;

use App\Domain\Collection\Collection;
use Doctrine\Common\Collections\Criteria;

/**
 * @method static __construct(Download[] $elements)
 * @method Download[] toArray()
 * @method Download first()
 * @method Download last()
 * @method int key()
 * @method Download next()
 * @method Download current()
 * @method bool remove(int $key)
 * @method bool removeElement(Download $element)
 * @method bool containsKey(int $key)
 * @method bool contains(Download $element)
 * @method mixed indexOf(Download $element)
 * @method Download get(int $key)
 * @method int[] getKeys()
 * @method Download[] getValues()
 * @method void set(int $key, Download $element)
 * @method bool add(Download $element)
 * @method \ArrayIterator|Download[] getIterator()
 * @method Download[]|Downloads map(\Closure $p)
 * @method Download[]|Downloads filter(\Closure $p)
 * @method Downloads[] partition(\Closure $p)
 * @method Download[]|Downloads slice($offset, $length = null)
 * @method Download[]|Downloads matching(Criteria $criteria)
 */
final class Downloads extends Collection
{
}
