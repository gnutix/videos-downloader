<?php declare(strict_types=1);

namespace App\Filesystem;

use App\Collection\Collection;
use Doctrine\Common\Collections\Criteria;

/**
 * @method static __construct(\SplFileInfo[] $elements)
 * @method \SplFileInfo[] toArray()
 * @method \SplFileInfo first()
 * @method \SplFileInfo last()
 * @method string key()
 * @method \SplFileInfo next()
 * @method \SplFileInfo current()
 * @method bool remove(string $key)
 * @method bool removeElement(\SplFileInfo $element)
 * @method bool containsKey(string $key)
 * @method bool contains(\SplFileInfo $element)
 * @method string indexOf(\SplFileInfo $element)
 * @method \SplFileInfo get(string $key)
 * @method string[] getKeys()
 * @method \SplFileInfo[] getValues()
 * @method void set(string $key, \SplFileInfo $element)
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
