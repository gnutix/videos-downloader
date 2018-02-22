<?php declare(strict_types=1);

namespace Extension\YouTubeDl;

use App\Domain\Path;
use App\Domain\Download as DownloadInterface;

final class Download implements DownloadInterface
{
    /** @var \App\Domain\Path */
    private $path;

    /** @var string */
    private $downloader;

    /** @var string */
    private $videoId;

    /** @var string */
    private $videoUrl;

    /** @var string */
    private $fileType;

    /** @var string */
    private $fileExtension;

    /**
     * @param \App\Domain\Path $path
     * @param string $downloader
     * @param string $videoId
     * @param string $videoUrl
     * @param string $fileType
     * @param string $fileExtension
     */
    public function __construct(
        Path $path,
        string $downloader,
        string $videoId,
        string $videoUrl,
        string $fileType,
        string $fileExtension
    ) {
        $this->path = $path;
        $this->downloader = $downloader;
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
     * @return \App\Domain\Path
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
        return $this->downloader;
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
