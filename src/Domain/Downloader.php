<?php declare(strict_types=1);

namespace App\Domain;

use App\Filesystem\FilesystemCleaner;
use App\UI\Skippable;
use Symfony\Component\Filesystem\Filesystem;

abstract class Downloader extends ContentsProcessor implements RootPathPartAware
{
    use Skippable;

    /** @var \App\Domain\PathPart */
    private $rootPathPart;

    /**
     * @return Downloads
     */
    abstract protected function createDownloadsCollection(): Downloads;

    /**
     * @param \App\Domain\Downloads $downloads
     *
     * @return \App\Domain\Downloads
     */
    abstract protected function filterAlreadyDownloaded(Downloads $downloads): Downloads;

    /**
     * @param \App\Domain\Content $content
     *
     * @return \App\Domain\Downloads
     */
    abstract protected function extractDownloads(Content $content): Downloads;

    /**
     * @param \App\Domain\Downloads $downloads
     * @param \App\Domain\Path $downloadPath
     *
     * @throws \RuntimeException
     */
    abstract protected function download(Downloads $downloads, Path $downloadPath): void;

    /**
     * {@inheritdoc}
     */
    public function setRootPathPart(PathPart $rootPathPart): void
    {
        $this->rootPathPart = $rootPathPart;
    }

    /**
     * {@inheritdoc}
     * @throws \RuntimeException
     * @throws \Symfony\Component\Filesystem\Exception\IOException
     */
    public function processContents(Contents $contents): void
    {
        if ($contents->isEmpty()) {
            return;
        }

        $downloaderPathPart = new PathPart($this->config['path_part'] ?? []);
        $downloadPath = new Path([$this->rootPathPart, $downloaderPathPart]);

        // Try to create the downloads directory... 'cause if it fails, nothing will work.
        (new Filesystem())->mkdir((string) $downloadPath);

        if ($this->shouldCleanFilesystem()) {
            (new FilesystemCleaner($this->ui))($downloadPath);
        }

        // Add the downloader path part and get a collection of downloads
        $downloads = $this->createDownloadsCollection();
        foreach ($contents as $content) {
            $content->getPath()->add($downloaderPathPart);

            foreach ($this->extractDownloads($content) as $download) {
                $downloads->add($download);
            }
        }

        $downloads = $this->filterAlreadyDownloaded($downloads);

        if ($this->shouldDownload($downloads, $downloadPath)) {
            $this->download($downloads, $downloadPath);
        }
    }

    /**
     * @return bool
     */
    protected function shouldCleanFilesystem(): bool
    {
        return $this->config['clean_filesystem'] ?? true;
    }

    /**
     * @param \App\Domain\Downloads $downloads
     * @param \App\Domain\Path $downloadPath
     *
     * @return bool
     */
    private function shouldDownload(Downloads $downloads, Path $downloadPath): bool
    {
        $this->ui->writeln(sprintf('Downloading files with <info>%s</info>... '.PHP_EOL, static::class));

        return $this->shouldProcess(
            $this->ui,
            $downloads,
            sprintf(
                '%sThe script is about to download <question> %s </question> files into <info>%s</info>. '.PHP_EOL,
                $this->ui->indent(),
                $downloads->count(),
                (string) $downloadPath
            ),
            'download'
        );
    }
}
