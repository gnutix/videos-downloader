<?php declare(strict_types=1);

namespace App\Platform\YouTube;

use App\Domain\Collection;
use App\Domain\Content;
use App\Domain\Path;
use App\Domain\PathPart;
use App\Platform\Platform;
use App\UI\UserInterface;
use App\YoutubeDl\Exception as YoutubeDlException;
use App\YoutubeDl\YoutubeDl;
use Symfony\Component\Filesystem\Filesystem;

final class YouTube implements Platform
{
    use FilesystemManager;

    // See https://stackoverflow.com/a/37704433/389519
    private const YOUTUBE_URL_REGEX = <<<REGEX
/\b((?:https?:)?\/\/)?((?:www|m)\.)?((?:youtube\.com|youtu.be))(\/(?:[\w\-]+\?v=|embed\/|v\/)?)([\w\-]+)(\S+)?/i
REGEX;
    private const YOUTUBE_URL_REGEX_MATCHES_ID_INDEX = 5;
    private const YOUTUBE_URL_PREFIX = 'https://www.youtube.com/watch?v=';

    /**
     * @param UserInterface $ui
     * @param array $options
     * @param bool $dryRun
     */
    public function __construct(UserInterface $ui, array $options, bool $dryRun = false)
    {
        $this->ui = $ui;
        $this->options = $options;
        $this->dryRun = $dryRun;
    }

    /**
     * @param \App\Domain\Content[]|\App\Domain\Collection $contents
     * @param \App\Domain\PathPart $rootPathPart
     * @throws \RuntimeException
     */
    public function synchronizeContents(Collection $contents, PathPart $rootPathPart): void
    {
        if ($contents->isEmpty()) {
            return;
        }

        $platformPathPart = new PathPart($this->options['path_part']);
        $downloadPath = new Path([$rootPathPart, $platformPathPart]);

        // Try to create the downloads directory... 'cause if it fails, nothing will work.
        (new Filesystem())->mkdir((string) $downloadPath);

        // Add the platform path part and get a collection of downloads
        $downloads = new Collection();
        foreach ($contents as $content) {
            $content->getPath()->add($platformPathPart);

            foreach ($this->extractDownloads($content) as $download) {
                $downloads->add($download);
            }
        }

        $this->cleanFilesystem($downloads, $downloadPath);

        $downloads = $this->filterAlreadyDownloaded($downloads);

        if ($this->shouldDownload($downloads, $downloadPath)) {
            $this->download($downloads);
        }
    }

    /**
     * @param \App\Domain\Content $content
     *
     * @return \App\Platform\YouTube\Download[]|\App\Domain\Collection
     */
    private function extractDownloads(Content $content): Collection
    {
        $downloads = new Collection();

        if (preg_match_all(static::YOUTUBE_URL_REGEX, $content->getData(), $youtubeUrls)) {
            foreach ((array) $youtubeUrls[static::YOUTUBE_URL_REGEX_MATCHES_ID_INDEX] as $youtubeId) {
                foreach (array_keys($this->options['youtube_dl']['options']) as $videoFileType) {
                    $downloads->add(
                        new Download(
                            $content->getPath(),
                            $youtubeId,
                            $videoFileType,
                            $this->options['patterns']['extensions'][$videoFileType]
                        )
                    );
                }
            }
        }

        return $downloads;
    }

    /**
     * @param \App\Platform\YouTube\Download[]|\App\Domain\Collection $downloads
     *
     * @return \App\Platform\YouTube\Download[]|\App\Domain\Collection
     */
    private function filterAlreadyDownloaded(Collection $downloads): Collection
    {
        return $downloads->filter(
            function (Download $download) {
                $shouldBeDownloaded = true;
                try {
                    if ($this->getDownloadFolderFinder($download)->hasResults()) {
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
     * @param \App\Platform\YouTube\Download[]|\App\Domain\Collection $downloads
     * @param \App\Domain\Path $downloadPath
     *
     * @return bool
     */
    private function shouldDownload(Collection $downloads, Path $downloadPath): bool
    {
        $this->ui->writeln('Download files from YouTube... '.PHP_EOL);

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
        if ($this->skip() || !$this->ui->confirm()) {
            $this->ui->writeln(PHP_EOL.'<info>Done.</info>'.PHP_EOL);

            return false;
        }

        return true;
    }

    /**
     * @param \App\Platform\YouTube\Download[]|\App\Domain\Collection $downloads
     *
     * @throws \RuntimeException
     */
    private function download(Collection $downloads)
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
                    $download->getPath()
                )
            );

            $options = $this->options['youtube_dl']['options'][$download->getFileType()];
            $nbAttempts = \count($options);

            $attempt = 0;
            while (true) {
                try {
                    $this->doDownload($download, $options[$attempt]);

                    $this->ui->writeln('<info>Done.</info>');
                    break;

                } catch (YoutubeDlException\ChannelRemovedByUserException
                    | YoutubeDlException\VideoBlockedByCopyrightException
                    | YoutubeDlException\VideoRemovedByUserException
                    | YoutubeDlException\VideoUnavailableException
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
     * @param \App\Platform\YouTube\Download $download
     * @param array $youtubeDlOptions
     *
     * @throws \Exception
     */
    private function doDownload(Download $download, array $youtubeDlOptions)
    {
        $dl = new YoutubeDl($youtubeDlOptions);
        $dl->setDownloadPath((string) $download->getPath());

        try {
            (new Filesystem())->mkdir((string) $download->getPath());
            $dl->download(static::YOUTUBE_URL_PREFIX.$download->getVideoId());
        } catch (\Exception $e) {

            // Add more custom exceptions than those already provided by YoutubeDl
            if (preg_match('/this video is unavailable/i', $e->getMessage())) {
                throw new YoutubeDlException\VideoUnavailableException(
                    sprintf('The video %s is unavailable.', $download->getVideoId()), 0, $e
                );
            }
            if (preg_match('/this video has been removed by the user/i', $e->getMessage())) {
                throw new YoutubeDlException\VideoRemovedByUserException(
                    sprintf('The video %s has been removed by its user.', $download->getVideoId()), 0, $e
                );
            }
            if (preg_match('/the uploader has closed their YouTube account/i', $e->getMessage())) {
                throw new YoutubeDlException\ChannelRemovedByUserException(
                    sprintf('The channel that published the video %s has been removed.', $download->getVideoId()), 0, $e
                );
            }
            if (preg_match('/who has blocked it on copyright grounds/i', $e->getMessage())) {
                throw new YoutubeDlException\VideoBlockedByCopyrightException(
                    sprintf('The video %s has been block for copyright infringement.', $download->getVideoId()), 0, $e
                );
            }

            throw $e;
        }
    }
}
