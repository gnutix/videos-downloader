<?php declare(strict_types=1);

namespace App\Platform\YouTube;

final class Download
{
    /** @var string */
    private $fileType;

    /** @var string */
    private $fileExtension;

    /** @var string */
    private $videoId;

    /** @var string */
    private $path;

    /**
     * @param string $fileType
     * @param string $fileExtension
     * @param string $videoId
     * @param string $path
     */
    public function __construct(
        string $fileType,
        string $fileExtension,
        string $videoId,
        string $path
    ) {
        $this->fileType = $fileType;
        $this->fileExtension = $fileExtension;
        $this->videoId = $videoId;
        $this->path = $path;
    }

    /**
     * @return string
     */
    public function __toString(): string
    {
        return (string) str_replace(DIRECTORY_SEPARATOR, ' - ', trim($this->path, DIRECTORY_SEPARATOR));
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
    public function getPath(): string
    {
        return rtrim($this->path, DIRECTORY_SEPARATOR);
    }
}
