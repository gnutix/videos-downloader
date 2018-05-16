<?php declare(strict_types=1);

namespace Extension\YouTubePlaylist;

use App\Collection\Collection;
use Doctrine\Common\Collections\Criteria;
use Google_Service_YouTube_PlaylistItem;

/**
 * @method static __construct(Google_Service_YouTube_PlaylistItem[] $elements)
 * @method Google_Service_YouTube_PlaylistItem[] toArray()
 * @method Google_Service_YouTube_PlaylistItem first()
 * @method Google_Service_YouTube_PlaylistItem last()
 * @method Google_Service_YouTube_PlaylistItem next()
 * @method Google_Service_YouTube_PlaylistItem current()
 * @method bool removeElement(Google_Service_YouTube_PlaylistItem $element)
 * @method bool contains(Google_Service_YouTube_PlaylistItem $element)
 * @method mixed indexOf(Google_Service_YouTube_PlaylistItem $element)
 * @method Google_Service_YouTube_PlaylistItem[] getValues()
 * @method void set($key, Google_Service_YouTube_PlaylistItem $element)
 * @method bool add(Google_Service_YouTube_PlaylistItem $element)
 * @method \ArrayIterator|Google_Service_YouTube_PlaylistItem[] getIterator()
 * @method Google_Service_YouTube_PlaylistItem[]|PlaylistItems map(\Closure $p)
 * @method Google_Service_YouTube_PlaylistItem[]|PlaylistItems filter(\Closure $p)
 * @method PlaylistItems[] partition(\Closure $p)
 * @method Google_Service_YouTube_PlaylistItem[]|PlaylistItems matching(Criteria $criteria)
 */
final class PlaylistItems extends Collection
{
}
