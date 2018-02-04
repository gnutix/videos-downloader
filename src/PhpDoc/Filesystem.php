<?php declare(strict_types=1);

namespace App\PhpDoc;

use League\Flysystem\Adapter\AbstractAdapter;
use League\Flysystem\FilesystemInterface;

/**
 * @method AbstractAdapter getAdapter()
 */
interface Filesystem extends FilesystemInterface
{
}
