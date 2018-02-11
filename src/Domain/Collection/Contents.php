<?php declare(strict_types=1);

namespace App\Domain\Collection;

use App\Domain\Content;
use Doctrine\Common\Collections\Criteria;

/**
 * @method static __construct(Content[] $elements)
 * @method Content[] toArray()
 * @method Content first()
 * @method Content last()
 * @method int key()
 * @method Content next()
 * @method Content current()
 * @method bool remove(int $key)
 * @method bool removeElement(Content $element)
 * @method bool containsKey(int $key)
 * @method bool contains(Content $element)
 * @method mixed indexOf(Content $element)
 * @method Content get(int $key)
 * @method int[] getKeys()
 * @method Content[] getValues()
 * @method void set(int $key, Content $element)
 * @method bool add(Content $element)
 * @method \ArrayIterator|Content[] getIterator()
 * @method Content[]|Contents map(\Closure $p)
 * @method Content[]|Contents filter(\Closure $p)
 * @method Contents[] partition(\Closure $p)
 * @method Content[]|Contents slice($offset, $length = null)
 * @method Content[]|Contents matching(Criteria $criteria)
 */
final class Contents extends Collection
{
}
