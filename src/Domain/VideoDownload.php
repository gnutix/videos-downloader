<?php declare(strict_types=1);

namespace App\Domain;

use Ramsey\Uuid\UuidInterface;

final class VideoDownload
{
    /** @var \Ramsey\Uuid\UuidInterface */
    private $id;

    /** @var string */
    private $fileType;

    /** @var string */
    private $fileExtension;

    /** @var string */
    private $videoId;

    /** @var string */
    private $path;

    /**
     * @param \Ramsey\Uuid\UuidInterface $id
     * @param string $fileType
     * @param string $fileExtension
     * @param string $videoId
     * @param string $path
     */
    public function __construct(
        UuidInterface $id,
        string $fileType,
        string $fileExtension,
        string $videoId,
        string $path
    ) {
        $this->id = $id;
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
     * @return \Ramsey\Uuid\UuidInterface
     */
    public function getId(): UuidInterface
    {
        return $this->id;
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
