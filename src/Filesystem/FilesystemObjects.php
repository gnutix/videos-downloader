<?php declare(strict_types=1);

namespace App\Filesystem;

use App\Domain\Collection\Collection;
use Doctrine\Common\Collections\Criteria;

/**
 * @method static __construct(\SplFileInfo[] $elements)
 * @method \SplFileInfo[] toArray()
 * @method \SplFileInfo first()
 * @method \SplFileInfo last()
 * @method int key()
 * @method \SplFileInfo next()
 * @method \SplFileInfo current()
 * @method bool remove(int $key)
 * @method bool removeElement(\SplFileInfo $element)
 * @method bool containsKey(int $key)
 * @method bool contains(\SplFileInfo $element)
 * @method mixed indexOf(\SplFileInfo $element)
 * @method \SplFileInfo get(int $key)
 * @method int[] getKeys()
 * @method \SplFileInfo[] getValues()
 * @method void set(int $key, \SplFileInfo $element)
 * @method bool add(\SplFileInfo $element)
 * @method \ArrayIterator|\SplFileInfo[] getIterator()
 * @method \SplFileInfo[]|FilesystemObjects map(\Closure $p)
 * @method \SplFileInfo[]|FilesystemObjects filter(\Closure $p)
 * @method FilesystemObjects[] partition(\Closure $p)
 * @method \SplFileInfo[]|FilesystemObjects matching(Criteria $criteria)
 */
final class FilesystemObjects extends Collection
{
}
