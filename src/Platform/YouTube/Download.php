<?php declare(strict_types=1);

namespace App\Platform\YouTube;

use App\Domain\Path;

final class Download
{
    /** @var \App\Domain\Path */
    private $path;

    /** @var string */
    private $videoId;

    /** @var string */
    private $fileType;

    /** @var string */
    private $fileExtension;

    /**
     * @param \App\Domain\Path $path
     * @param string $videoId
     * @param string $fileType
     * @param string $fileExtension
     */
    public function __construct(
        Path $path,
        string $videoId,
        string $fileType,
        string $fileExtension
    ) {
        $this->path = $path;
        $this->videoId = $videoId;
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
    public function getVideoId(): string
    {
        return $this->videoId;
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
