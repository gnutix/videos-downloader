<?php declare(strict_types=1);

namespace Extension\File;

use App\Domain\Path;
use App\Domain\Download as DownloadInterface;

final class Download implements DownloadInterface
{
    /** @var string */
    private $url;

    /** @var \App\Domain\Path */
    private $path;

    /**
     * @param string $url
     * @param \App\Domain\Path $path
     */
    public function __construct(string $url, Path $path)
    {
        $this->url = $url;
        $this->path = $path;
    }

    /**
     * @return string
     */
    public function __toString(): string
    {
        return (string) str_replace(DIRECTORY_SEPARATOR, ' - ', trim((string) $this->getPath(), DIRECTORY_SEPARATOR));
    }

    /**
     * @return string
     */
    public function getUrl(): string
    {
        return $this->url;
    }

    /**
     * @return \App\Domain\Path
     */
    public function getPath(): Path
    {
        return $this->path;
    }
}
