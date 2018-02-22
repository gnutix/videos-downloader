<?php declare(strict_types=1);

namespace Extension\YouTube;

use App\Domain\Content;
use App\Domain\Downloader;
use App\Domain\Path;
use App\Domain\Download as DownloadInterface;
use App\Domain\Downloads as DownloadsInterface;
use App\Domain\PathPart;
use Extension\YouTube\Exception as YouTubeException;
use Symfony\Component\Filesystem\Filesystem;

final class YouTube extends Downloader
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
     * {@inheritdoc}
     * @param \Extension\YouTube\Download $download
     */
    protected function getDownloadFolder(DownloadInterface $download): string
    {
        $downloadPathPart = new PathPart([
            'path' => (string) $download->getPath(),
            'priority' => 0,
        ]);
        $folderPathPart = new PathPart([
            'path' => $this->config['downloaders'][$download->getPlatform()]['downloader']['folder'],
            'priority' => 1,
            'substitutions' => $this->getDownloadPlaceholders($download),
        ]);

        return (string) new Path([$downloadPathPart, $folderPathPart]);
    }

    /**
     * {@inheritdoc}
     */
    protected function extractDownloads(Content $content): DownloadsInterface
    {
        $downloads = $this->createDownloadsCollection();

        // Loop over each extractor
        foreach ((array) $this->config['downloaders'] as $downloader => $downloaderConfig) {

            // Apply the regex to the content's data
            if (!preg_match_all($downloaderConfig['extractor']['regex'], $content->getData(), $matches)) {
                continue;
            }

            // Loop over each URL that was extracted
            foreach ((array) $matches[$downloaderConfig['extractor']['video_url_index']] as $index => $videoUrl) {
                $videoId = $matches[$downloaderConfig['extractor']['video_id_index']][$index];

                // Loop over each file format we'd like to download
                foreach ((array) $this->config['download_files'] as $videoFileType => $videoFileExtension) {
                    $downloads->add(
                        new Download(
                            $content->getPath(),
                            $downloader,
                            $videoId,
                            $videoUrl,
                            $videoFileType,
                            $videoFileExtension
                        )
                    );
                }
            }
        }

        return $downloads;
    }

    /**
     * {@inheritdoc}
     */
    protected function filterAlreadyDownloaded(DownloadsInterface $downloads): DownloadsInterface
    {
        return $downloads->filter(
            function (Download $download) {
                $shouldBeDownloaded = true;
                try {
                    $placeholders = $this->getDownloadPlaceholders($download);

                    $downloadFinder = $this->getDownloadFolderFinder($download);
                    $downloadFinder->name(
                        str_replace(
                            array_keys($placeholders),
                            array_values($placeholders),
                            $this->config['downloaders'][$download->getPlatform()]['downloader']['filename']
                        )
                    );

                    if ($downloadFinder->hasResults()) {
                        $shouldBeDownloaded = false;
                    }
                } catch (\InvalidArgumentException $e) {
                    // Here we know that the download folder will exist.
                }

                return $shouldBeDownloaded;
            }
        );
    }

    /**
     * {@inheritdoc}
     */
    protected function download(DownloadsInterface $downloads, Path $downloadPath): void
    {
        $errors = [];
        foreach ($downloads as $download) {
            $this->ui->write(
                sprintf(
                    '%s* [<comment>%s</comment>][<comment>%s</comment>] Download the %s file in <info>%s</info>... ',
                    $this->ui->indent(2),
                    $download->getVideoId(),
                    $download->getFileType(),
                    $download->getFileExtension(),
                    str_replace((string) $downloadPath.DIRECTORY_SEPARATOR, '', $download->getPath())
                )
            );

            $config = (array) $this->config['youtube_dl']['options'][$download->getFileType()];

            // Override "youtube_dl.options" by the downloaders' specific youtube-dl's options
            foreach ($config as $pass => $values) {
                $config[$pass] = array_merge(
                    $this->config['downloaders'][$download->getPlatform()]['downloader']['youtube_dl']['options'],
                    $values
                );
            }

            $nbAttempts = \count($config);
            $attempt = 0;
            while (true) {
                try {
                    $this->doDownload($download, $config[$attempt]);

                    $this->ui->writeln('<info>Done.</info>');
                    break;

                } catch (YouTubeException\ChannelRemovedByUserException
                    | YouTubeException\VideoBlockedByCopyrightException
                    | YouTubeException\VideoRemovedByUserException
                    | YouTubeException\VideoUnavailableException
                $e) {
                    $this->ui->logError($e->getMessage(), $errors);
                    break;

                // These are (supposedly) connection/download errors, so we try again
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
     * @param \Extension\YouTube\Download $download
     * @param array $downloadOptions
     *
     * @throws \Exception
     */
    private function doDownload(Download $download, array $downloadOptions)
    {
        $youtubeDlOptions = $this->config['youtube_dl'];

        /** @var \YouTubeDl\YouTubeDl $dl */
        $dl = new $youtubeDlOptions['class_name']($downloadOptions);
        $dl->setDownloadPath((string) $download->getPath());

        try {
            (new Filesystem())->mkdir((string) $download->getPath());
            $dl->download($download->getVideoUrl());
        } catch (\Exception $e) {

            // Add more custom exceptions than those already provided by YoutubeDl
            if (preg_match('/this video is unavailable/i', $e->getMessage())) {
                throw new YouTubeException\VideoUnavailableException(
                    sprintf('The video %s is unavailable.', $download->getVideoId()), 0, $e
                );
            }
            if (preg_match('/this video has been removed by the user/i', $e->getMessage())) {
                throw new YouTubeException\VideoRemovedByUserException(
                    sprintf('The video %s has been removed by its user.', $download->getVideoId()), 0, $e
                );
            }
            if (preg_match('/the uploader has closed their YouTube account/i', $e->getMessage())) {
                throw new YouTubeException\ChannelRemovedByUserException(
                    sprintf('The channel that published the video %s has been removed.', $download->getVideoId()), 0, $e
                );
            }
            if (preg_match('/who has blocked it on copyright grounds/i', $e->getMessage())) {
                throw new YouTubeException\VideoBlockedByCopyrightException(
                    sprintf('The video %s has been block for copyright infringement.', $download->getVideoId()), 0, $e
                );
            }

            throw $e;
        }
    }

    /**
     * @param \Extension\YouTube\Download $download
     *
     * @return array
     */
    private function getDownloadPlaceholders(Download $download): array
    {
        return [
            '%video_id%' => $download->getVideoId(),
            '%file_extension%' => $download->getFileExtension(),
        ];
    }
}
