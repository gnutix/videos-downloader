<?php declare(strict_types=1);

namespace App\Domain;

use App\Filesystem\FilesystemManager;
use App\UI\UserInterface;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Yaml\Yaml;

abstract class Downloader extends FilesystemManager
{
    /** @var array */
    protected $config;

    /**
     * {@inheritdoc}
     * @param array $config
     *
     * @throws \Symfony\Component\Yaml\Exception\ParseException
     */
    public function __construct(UserInterface $ui, array $config = [])
    {
        parent::__construct($ui);

        if (!empty($configFilePath = $this->getConfigFilePath())) {
            $config += (array) Yaml::parseFile($configFilePath);
        }

        $this->config = $config;
    }

    /**
     * {@inheritdoc}
     * @throws \RuntimeException
     * @throws \Symfony\Component\Filesystem\Exception\IOException
     */
    public function synchronizeContents(Contents $contents, PathPart $rootPathPart): void
    {
        if ($contents->isEmpty()) {
            return;
        }

        $downloaderPathPart = new PathPart($this->config['path_part'] ?? []);
        $downloadPath = new Path([$rootPathPart, $downloaderPathPart]);

        // Try to create the downloads directory... 'cause if it fails, nothing will work.
        (new Filesystem())->mkdir((string) $downloadPath);

        // Add the downloader path part and get a collection of downloads
        $downloads = $this->createDownloadsCollection();
        foreach ($contents as $content) {
            $content->getPath()->add($downloaderPathPart);

            foreach ($this->extractDownloads($content) as $download) {
                $downloads->add($download);
            }
        }

        $this->cleanFilesystem($downloads, $downloadPath);

        $downloads = $this->filterAlreadyDownloaded($downloads);

        if ($this->shouldDownload($downloads, $downloadPath)) {
            $this->download($downloads, $downloadPath);
        }
    }

    /**
     * @return string
     */
    abstract protected function getConfigFilePath(): string;

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
     * @param \App\Domain\Downloads $downloads
     * @param \App\Domain\Path $downloadPath
     *
     * @return bool
     */
    private function shouldDownload(Downloads $downloads, Path $downloadPath): bool
    {
        $this->ui->writeln(sprintf('Downloading files with <info>%s</info>... '.PHP_EOL, static::class));

        if ($downloads->isEmpty()) {
            $this->ui->writeln($this->ui->indent().'<comment>Nothing to download.</comment>'.PHP_EOL);

            return false;
        }

        $this->ui->writeln(
            sprintf(
                '%sThe script is about to download <question> %s </question> files into <info>%s</info>. '.PHP_EOL,
                $this->ui->indent(),
                $downloads->count(),
                (string) $downloadPath
            )
        );

        $this->ui->write($this->ui->indent());
        if ($this->skip($this->ui) || !$this->ui->confirm()) {
            $this->ui->writeln(($this->ui->isDryRun() ? '' : PHP_EOL).'<info>Done.</info>'.PHP_EOL);

            return false;
        }

        return true;
    }
}
