<?php declare(strict_types=1);

namespace Extension\YouTube;

use App\Domain\Collection\Path;
use App\Domain\Download as DownloadInterface;

final class Download implements DownloadInterface
{
    /** @var \App\Domain\Collection\Path */
    private $path;

    /** @var string */
    private $platform;

    /** @var string */
    private $videoId;

    /** @var string */
    private $videoUrl;

    /** @var string */
    private $fileType;

    /** @var string */
    private $fileExtension;

    /**
     * @param \App\Domain\Collection\Path $path
     * @param string $platform
     * @param string $videoId
     * @param string $videoUrl
     * @param string $fileType
     * @param string $fileExtension
     */
    public function __construct(
        Path $path,
        string $platform,
        string $videoId,
        string $videoUrl,
        string $fileType,
        string $fileExtension
    ) {
        $this->path = $path;
        $this->platform = $platform;
        $this->videoId = $videoId;
        $this->videoUrl = $videoUrl;
        $this->fileType = $fileType;
        $this->fileExtension = $fileExtension;
    }

    /**
     * @return string
     */
    public function __toString(): string
    {
        return (string) str_replace(DIRECTORY_SEPARATOR, ' - ', trim((string) $this->getPath(), DIRECTORY_SEPARATOR));
    }

    /**
     * @return \App\Domain\Collection\Path
     */
    public function getPath(): Path
    {
        return $this->path;
    }

    /**
     * @return string
     */
    public function getPlatform(): string
    {
        return $this->platform;
    }

    /**
     * @return string
     */
    public function getVideoId(): string
    {
        return $this->videoId;
    }

    /**
     * @return string
     */
    public function getVideoUrl(): string
    {
        return $this->videoUrl;
    }

    /**
     * @return string
     */
    public function getFileType(): string
    {
        return $this->fileType;
    }

    /**
     * @return string
     */
    public function getFileExtension(): string
    {
        return $this->fileExtension;
    }
}
