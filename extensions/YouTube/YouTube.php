<?php declare(strict_types=1);

namespace Extension\YouTube;

use App\Domain\Collection\Contents;
use App\Domain\Content;
use App\Domain\Collection\Path;
use App\Domain\Download as DownloadInterface;
use App\Domain\PathPart;
use App\Filesystem\FilesystemManager;
use App\Domain\Platform;
use Symfony\Component\Yaml\Yaml;
use Extension\YouTube\Exception as YouTubeException;
use App\UI\UserInterface;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Finder\Finder;

final class YouTube extends FilesystemManager implements Platform
{
    // See https://stackoverflow.com/a/37704433/389519
    private const YOUTUBE_URL_REGEX = <<<REGEX
/\b((?:https?:)?\/\/)?((?:www|m)\.)?((?:youtube\.com|youtu.be))(\/(?:[\w\-]+\?v=|embed\/|v\/)?)([\w\-]+)(\S+)?/i
REGEX;
    private const YOUTUBE_URL_REGEX_MATCHES_ID_INDEX = 5;
    private const YOUTUBE_URL_PREFIX = 'https://www.youtube.com/watch?v=';

    /** @var array */
    private $options;

    /**
     * @param \App\UI\UserInterface $ui
     * @param array $config
     *
     * @throws \Symfony\Component\Yaml\Exception\ParseException
     */
    public function __construct(UserInterface $ui, array $config = [])
    {
        parent::__construct($ui);

        $this->options = array_merge(
            (array) Yaml::parseFile(__DIR__.DIRECTORY_SEPARATOR.'config/config.yml'),
            $config
        );
    }

    /**
     * @param \App\Domain\Collection\Contents $contents
     * @param \App\Domain\PathPart $rootPathPart
     *
     * @throws \RuntimeException
     */
    public function synchronizeContents(Contents $contents, PathPart $rootPathPart): void
    {
        if ($contents->isEmpty()) {
            return;
        }

        $platformPathPart = new PathPart($this->options['path_part']);
        $downloadPath = new Path([$rootPathPart, $platformPathPart]);

        // Try to create the downloads directory... 'cause if it fails, nothing will work.
        (new Filesystem())->mkdir((string) $downloadPath);

        // Add the platform path part and get a collection of downloads
        $downloads = new Downloads();
        foreach ($contents as $content) {
            $content->getPath()->add($platformPathPart);

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

    /** @noinspection LowerAccessLevelInspection */
    /**
     * @param \App\Domain\Collection\Path $downloadPath
     *
     * @return \Symfony\Component\Finder\Finder
     */
    protected function getAllDownloadsFolderFinder(Path $downloadPath): Finder
    {
        /** @noinspection ExceptionsAnnotatingAndHandlingInspection */
        return (new Finder())
            ->directories()
            ->in((string) $downloadPath)
            ->sort(function (\SplFileInfo $fileInfoA, \SplFileInfo $fileInfoB) {
                // Sort the result by folder depth
                $a = substr_count($fileInfoA->getRealPath(), DIRECTORY_SEPARATOR);
                $b = substr_count($fileInfoB->getRealPath(), DIRECTORY_SEPARATOR);

                return $a <=> $b;
            });
    }

    /** @noinspection LowerAccessLevelInspection */
    /**
     * {@inheritdoc}
     * @param \Extension\YouTube\Download $download
     */
    protected function getDownloadFolderFinder(DownloadInterface $download): Finder
    {
        $placeholders = [
            '%video_id%' => $download->getVideoId(),
            '%file_extension%' => $download->getFileExtension(),
        ];

        $downloadPathPart = new PathPart([
            'path' => (string) $download->getPath(),
            'priority' => 0,
        ]);
        $folderPathPart = new PathPart([
            'path' => $this->options['patterns']['folder'],
            'priority' => 1,
            'substitutions' => $placeholders,
        ]);

        return (new Finder())
            ->files()
            ->depth('== 0')
            ->in((string) new Path([$downloadPathPart, $folderPathPart]))
            ->name(
                str_replace(
                    array_keys($placeholders),
                    array_values($placeholders),
                    $this->options['patterns']['filename']
                )
            );
    }

    /**
     * @param \App\Domain\Content $content
     *
     * @return \Extension\YouTube\Downloads
     */
    private function extractDownloads(Content $content): Downloads
    {
        $downloads = new Downloads();

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
     * @param \Extension\YouTube\Downloads $downloads
     *
     * @return \Extension\YouTube\Downloads
     */
    private function filterAlreadyDownloaded(Downloads $downloads): Downloads
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
     * @param \Extension\YouTube\Downloads $downloads
     * @param \App\Domain\Collection\Path $downloadPath
     *
     * @return bool
     */
    private function shouldDownload(Downloads $downloads, Path $downloadPath): bool
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
            $this->ui->writeln(($this->ui->isDryRun() ? '' : PHP_EOL).'<info>Done.</info>'.PHP_EOL);

            return false;
        }

        return true;
    }

    /**
     * @param \Extension\YouTube\Downloads $downloads
     * @param \App\Domain\Collection\Path $downloadPath
     *
     * @throws \RuntimeException
     */
    private function download(Downloads $downloads, Path $downloadPath)
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

            $options = $this->options['youtube_dl']['options'][$download->getFileType()];
            $nbAttempts = \count($options);

            $attempt = 0;
            while (true) {
                try {
                    $this->doDownload($download, $options[$attempt]);

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
        $youtubeDlOptions = $this->options['youtube_dl'];

        /** @var \YouTubeDl\YouTubeDl $dl */
        $dl = new $youtubeDlOptions['class_name']($downloadOptions);
        $dl->setDownloadPath((string) $download->getPath());

        try {
            (new Filesystem())->mkdir((string) $download->getPath());
            $dl->download(static::YOUTUBE_URL_PREFIX.$download->getVideoId());
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
}
