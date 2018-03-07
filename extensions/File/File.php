<?php declare(strict_types=1);

namespace Extension\File;

use App\Domain\Downloader;
use App\Domain\Path;
use App\Domain\Content;
use App\Domain\Download as DownloadInterface;
use App\Domain\Downloads as DownloadsInterface;
use App\Domain\PathPart;
use GuzzleHttp\Client;
use Symfony\Component\Filesystem\Filesystem;

final class File extends Downloader
{
    /**
     * {@inheritdoc}
     */
    protected function getConfigFilePath(): string
    {
        return __DIR__.DIRECTORY_SEPARATOR.'config/config.yml';
    }

    /**
     * {@inheritdoc}
     */
    protected function createDownloadsCollection(): DownloadsInterface
    {
        return new Downloads();
    }

    /**
     * @param \Extension\File\Download|DownloadInterface $download
     *
     * @return string
     */
    private function getDownloadFolder(DownloadInterface $download): string
    {
        return pathinfo((string) $download->getPath(), PATHINFO_DIRNAME);
    }

    /**
     * {@inheritdoc}
     * @throws \RuntimeException
     */
    protected function extractDownloads(Content $content): DownloadsInterface
    {
        $downloads = new Downloads();

        if (empty($this->config['extensions'])) {
            throw new \RuntimeException('The "extensions" configuration key is mandatory.');
        }

        $regex = str_replace('%extensions%', $this->config['extensions'], $this->config['extractor']['regex']);

        if (preg_match_all($regex, $content->getData(), $matches)) {
            foreach ((array) $matches[$this->config['extractor']['url_index']] as $url) {
                $path = Path::createFromPath($content->getPath());
                $path->add(new PathPart([
                    'path' => pathinfo(urldecode($url), PATHINFO_BASENAME),
                    'priority' => 255,
                ]));

                $downloads->add(new Download($url, $path));
            }
        }

        return $downloads;
    }

    /**
     * {@inheritdoc}
     */
    protected function filterAlreadyDownloaded(DownloadsInterface $downloads): DownloadsInterface
    {
        return $downloads->filter(function (Download $download) {
            return !file_exists((string) $download->getPath());
        });
    }

    /**
     * {@inheritdoc}
     * @param \Extension\File\Downloads|DownloadsInterface $downloads
     */
    protected function download(DownloadsInterface $downloads, Path $downloadPath): void
    {
        $errors = [];
        foreach ($downloads as $download) {
            $this->ui->write(
                sprintf(
                    '%s* Download the file <info>%s</info>... ',
                    $this->ui->indent(2),
                    str_replace((string) $downloadPath.DIRECTORY_SEPARATOR, '', (string) $download->getPath())
                )
            );

            $nbAttempts = $this->config['nb_attempts'] ?? 3;
            $attempt = 0;
            while (true) {
                try {
                    $this->doDownload($download);

                    $this->ui->writeln('<info>Done.</info>');
                    break;
                } catch (\Exception $e) {
                    $attempt++;

                    // Maximum number of attempts reached, move along...
                    if ($attempt === $nbAttempts) {
                        $this->ui->logError($e->getMessage(), $errors);
                        break;
                    }
                }
            }
        }
        $this->ui->displayErrors($errors, 'download of files', 'error', 1);

        $this->ui->writeln(PHP_EOL.'<info>Done.</info>'.PHP_EOL);
    }

    /**
     * @param \Extension\File\Download|\App\Domain\Download $download
     *
     * @throws \Symfony\Component\Filesystem\Exception\IOException
     */
    private function doDownload(DownloadInterface $download): void
    {
        (new Filesystem())->mkdir($this->getDownloadFolder($download));
        (new Client())->request('GET', $download->getUrl(), ['sink' => (string) $download->getPath()]);
    }
}
