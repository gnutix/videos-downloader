<?php declare(strict_types=1);

namespace Extension\PDF;

use App\Domain\Downloader;
use App\Domain\Path;
use App\Domain\Content;
use App\Domain\Download as DownloadInterface;
use App\Domain\Downloads as DownloadsInterface;
use App\Domain\PathPart;
use GuzzleHttp\Client;
use Symfony\Component\Filesystem\Filesystem;

final class PDF extends Downloader
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
     * @param \Extension\PDF\Download|DownloadInterface $download
     *
     * @return string
     */
    private function getDownloadFolder(DownloadInterface $download): string
    {
        return pathinfo((string) $download->getPath(), PATHINFO_DIRNAME);
    }

    /**
     * {@inheritdoc}
     */
    protected function extractDownloads(Content $content): DownloadsInterface
    {
        $downloads = new Downloads();

        if (preg_match_all($this->config['extractor']['regex'], $content->getData(), $matches)) {
            foreach ((array) $matches[$this->config['extractor']['pdf_url_index']] as $pdfLink) {
                $path = Path::createFromPath($content->getPath());
                $path->add(new PathPart([
                    'path' => pathinfo(urldecode($pdfLink), PATHINFO_BASENAME),
                    'priority' => 255,
                ]));

                $downloads->add(new Download($pdfLink, $path));
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
     * @param \Extension\PDF\Downloads|DownloadsInterface $downloads
     */
    protected function download(DownloadsInterface $downloads, Path $downloadPath): void
    {
        $errors = [];
        foreach ($downloads as $download) {
            $this->ui->write(
                sprintf(
                    '%s* Download the PDF file <info>%s</info>... ',
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
     * @param \Extension\PDF\Download|\App\Domain\Download $download
     *
     * @throws \Symfony\Component\Filesystem\Exception\IOException
     */
    private function doDownload(DownloadInterface $download): void
    {
        (new Filesystem())->mkdir($this->getDownloadFolder($download));
        (new Client())->request('GET', $download->getUrl(), ['sink' => (string) $download->getPath()]);
    }
}
