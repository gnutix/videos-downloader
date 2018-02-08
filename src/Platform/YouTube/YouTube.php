<?php declare(strict_types=1);

namespace App\Platform\YouTube;

use App\Cli\IOHelper;
use App\Kernel;
use App\Platform\Platform;
use App\Domain\VideoDownload;
use App\YoutubeDl\Exception as YoutubeDlException;
use App\YoutubeDl\YoutubeDl;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Finder\Finder;

final class YouTube implements Platform
{
    // See https://stackoverflow.com/a/37704433/389519
    private const YOUTUBE_URL_REGEX = '/\b((?:https?:)?\/\/)?((?:www|m)\.)?((?:youtube\.com|youtu.be))(\/(?:[\w\-]+\?v=|embed\/|v\/)?)([\w\-]+)(\S+)?/i';
    private const YOUTUBE_URL_REGEX_MATCHES_ID_INDEX = 5;
    private const YOUTUBE_URL_PREFIX = 'https://www.youtube.com/watch?v=';

    /** @var IOHelper */
    private $ioHelper;

    /** @var array */
    private $options;

    /** @var string */
    private $downloadsPath;

    /**
     * {@inheritdoc}
     * @throws \RuntimeException
     * @throws \Symfony\Component\Filesystem\Exception\IOException
     */
    public function __construct(IOHelper $ioHelper, array $options)
    {
        $this->ioHelper = $ioHelper;
        $this->options = $options;
        $this->downloadsPath = $this->getDownloadsPath();
    }

    /**
     * {@inheritdoc}
     */
    public function extractVideosIds($input): array
    {
        if (preg_match_all(static::YOUTUBE_URL_REGEX, $input, $youtubeUrls)) {
            $videoIds = [];

            foreach ((array) $youtubeUrls[static::YOUTUBE_URL_REGEX_MATCHES_ID_INDEX] as $youtubeId) {
                foreach (array_keys($this->options['youtube_dl']['options']) as $videoFileType) {
                    $videoIds[$videoFileType][$youtubeId]
                        = $this->options['downloads_paths']['video_files']['extensions'][$videoFileType];
                }
            }

            return $videoIds;
        }

        return [];
    }

    /**
     * {@inheritdoc}
     * @throws \RuntimeException
     */
    public function downloadVideos(array $videoDownloads): void
    {
        if (empty($videoDownloads)) {
            return;
        }

        $this->downloadVideosFromYouTube($this->prepareVideosForDownload($videoDownloads));
    }

    /**
     * @param \App\Domain\VideoDownload[] $videoDownloads
     *
     * @return array
     */
    private function prepareVideosForDownload(array $videoDownloads): array
    {
        $this->ioHelper->write('Skip the videos having their files already downloaded... ');

        /** @var \SplFileInfo[] $completedVideoDownloadsFolders */
        $completedVideoDownloadsFolders = [];

        // Passing the value by reference prevents PHP from creating a copy of the array
        foreach ($videoDownloads as &$videoDownload) {
            $placeholders = [
                '%video_id%' => $videoDownload->getVideoId(),
                '%file_extension%' => $videoDownload->getFileExtension(),
            ];

            /** @var \Symfony\Component\Finder\Finder $videoDownloadFolderFinder */
            try {
                $videoDownloadFolderFinder = (new Finder())
                    ->files()
                    ->depth('== 0')
                    ->in(
                        sprintf(
                            '%s'.DIRECTORY_SEPARATOR.'%s'.DIRECTORY_SEPARATOR.'%s',
                            $this->downloadsPath,
                            $videoDownload->getPath(),
                            str_replace(
                                array_keys($placeholders),
                                array_values($placeholders),
                                $this->options['downloads_paths']['video_files']['path']
                            )
                        )
                    )
                    ->name(
                        str_replace(
                            array_keys($placeholders),
                            array_values($placeholders),
                            $this->options['downloads_paths']['video_files']['filename']
                        )
                    );

                foreach ($videoDownloadFolderFinder as $videoDownloadFolder) {
                    unset($videoDownloads[(string) $videoDownload->getId()]);

                    $parentFolder = $videoDownloadFolder->getPathInfo();

                    // Using a key ensures there's no duplicates
                    $completedVideoDownloadsFolders[$parentFolder->getRealPath()] = $parentFolder;
                }
            } catch (\InvalidArgumentException $e) {
                // This is not the folder you're looking for. You can go about your business. Move along, move along...
            }
        }
        unset($videoDownload); // See https://alephnull.uk/call-unset-after-php-foreach-loop-values-passed-by-reference

        $this->ioHelper->writeln('<info>Done.</info>');
        $this->ioHelper->write(
            sprintf(
                'Synchronize the <info>%s</info> folder with the videos from the source... ',
                $this->downloadsPath
            )
        );

        // Here we can skip this inspection as we know the folder exists.
        /** @noinspection ExceptionsAnnotatingAndHandlingInspection */
        $this->removeFolders(
            iterator_to_array(
                (new Finder())
                    ->directories()
                    ->in($this->downloadsPath)
                    ->filter(function(\SplFileInfo $folder) use ($completedVideoDownloadsFolders) {

                        // Try exact folder matching
                        if (isset($completedVideoDownloadsFolders[$folder->getRealPath()])) {
                            return false;
                        }

                        // Try recursive parent folder matching
                        foreach ($completedVideoDownloadsFolders as $path) {
                            do {
                                // Get the parent folder
                                $path = $path->getPathInfo();

                                if ($path->getRealPath() === $folder->getRealPath()) {
                                    return false;
                                }

                                // Until we reach the downloads root folder
                            } while ($path->getRealPath() !== $this->downloadsPath);
                        }

                        return true;
                    })
            )
        );

        $this->ioHelper->writeln(PHP_EOL.'<info>Done.</info>'.PHP_EOL);

        return $videoDownloads;
    }

    /**
     * @param array $foldersToRemove
     *
     * @return bool Whether folders were removed or not.
     */
    private function removeFolders(array $foldersToRemove): bool
    {
        if (empty($foldersToRemove)) {
            return false;
        }

        $this->ioHelper->writeln(PHP_EOL);

        $confirmationDefault = true;
        $nbFoldersToRemove = \count($foldersToRemove);

        // If there's less than 10 folders, we can display them
        if ($nbFoldersToRemove <= 10) {
            $this->ioHelper->writeln(
                sprintf(
                    $this->i().'The script is about to remove the following folders from <info>%s</info>:',
                    $this->downloadsPath
                )
            );
            $this->ioHelper->listing(
                array_map(
                    function (string $path) {
                        return '<info>'.str_replace($this->downloadsPath.DIRECTORY_SEPARATOR, '', $path).'</info>';
                    },
                    $foldersToRemove
                ),
                3
            );
        } else {
            $confirmationDefault = false;

            $this->ioHelper->write(
                sprintf(
                    $this->i().'The script is about to remove <question> %s </question> folders from <info>%s</info>. ',
                    $nbFoldersToRemove,
                    $this->downloadsPath
                )
            );
        }

        $foldersWereRemoved = false;

        $this->ioHelper->write($this->i());
        if ($this->moveAlong($confirmationDefault)) {
            return $foldersWereRemoved;
        }

        $errors = [];
        foreach ($foldersToRemove as $folderToRemove) {
            $relativeFolderPath = $folderToRemove->getRelativePathname();

            try {
                (new Filesystem())->remove($folderToRemove->getRealPath());

                $foldersWereRemoved = true;

                $this->ioHelper->writeln(
                    sprintf(
                        '%s* The folder <info>%s</info> has been removed.',
                        str_repeat($this->i(), 2),
                        $relativeFolderPath
                    )
                );
            } catch (\Exception $e) {
                $this->logError(
                    sprintf(
                        '%s* <error>The folder %s could not be removed.</error>',
                        str_repeat($this->i(), 2),
                        $relativeFolderPath
                    ),
                    $errors
                );
            }
        }
        $this->displayErrors($errors, 'the removal of folders', 'info', 1);

        return $foldersWereRemoved;
    }

    /**
     * @param \App\Domain\VideoDownload[] $videoDownloads
     *
     * @throws \RuntimeException
     */
    private function downloadVideosFromYouTube(array $videoDownloads)
    {
        $this->ioHelper->writeln('Download videos from YouTube... '.PHP_EOL);

        if (empty($videoDownloads)) {
            $this->ioHelper->writeln('<comment>Nothing to download.</comment>');

            return;
        }

        $this->ioHelper->writeln(
            sprintf(
                $this->i().'The script is about to download <question> %s </question> files into <info>%s</info>. '.PHP_EOL,
                \count($videoDownloads),
                $this->downloadsPath
            )
        );

        $this->ioHelper->write($this->i());
        if ($this->moveAlong()) {
            $this->ioHelper->writeln(PHP_EOL.'<info>Done.</info>'.PHP_EOL);

            return;
        }

        $errors = [];
        foreach ($videoDownloads as $videoDownload) {
            $this->ioHelper->write(
                sprintf(
                    '%s* [<comment>%s</comment>][<comment>%s</comment>] Download the %s file in <info>%s</info>... ',
                    str_repeat($this->i(), 2),
                    $videoDownload->getVideoId(),
                    $videoDownload->getFileType(),
                    $videoDownload->getFileExtension(),
                    $videoDownload->getPath()
                )
            );

            $options = $this->options['youtube_dl']['options'][$videoDownload->getFileType()];
            $nbAttempts = \count($options);

            $attempt = 0;
            while (true) {
                try {
                    $this->downloadFromYouTube($videoDownload, $options[$attempt]);
                    break;

                // These are errors from YouTube like "video unavailable" or "account closed": there's no point trying.
                } catch (YoutubeDlException\CustomYoutubeDlException $e) {
                    $this->logError($e->getMessage(), $errors);
                    break;

                // These are (supposedly) connection/download errors, so we try again
                } catch (\Exception $e) {
                    $attempt++;

                    // Maximum number of attempts reached, move along...
                    if ($attempt === $nbAttempts) {
                        $this->logError($e->getMessage(), $errors);
                        break;
                    }
                }
            }
        }
        $this->displayErrors($errors, 'download of files', 'error', 1);

        $this->ioHelper->writeln(PHP_EOL.'<info>Done.</info>'.PHP_EOL);
    }

    /**
     * @param VideoDownload $videoDownload
     * @param array $youtubeDlOptions
     *
     * @throws \Exception
     */
    private function downloadFromYouTube(VideoDownload $videoDownload, array $youtubeDlOptions)
    {
        $path = $this->downloadsPath.DIRECTORY_SEPARATOR.$videoDownload->getPath();

        $dl = new YoutubeDl($youtubeDlOptions);
        $dl->setDownloadPath($path);

        try {
            (new Filesystem())->mkdir($path);
            $dl->download(static::YOUTUBE_URL_PREFIX.$videoDownload->getVideoId());
        } catch (\Exception $e) {

            // Add more custom exceptions than those already provided by YoutubeDl
            if (preg_match('/this video is unavailable/i', $e->getMessage())) {
                throw new YoutubeDlException\VideoUnavailableException(
                    'The video '.$videoDownload->getVideoId().' is unavailable.', 0, $e
                );
            }
            if (preg_match('/this video has been removed by the user/i', $e->getMessage())) {
                throw new YoutubeDlException\VideoRemovedByUserException(
                    'The video '.$videoDownload->getVideoId().' has been removed by its user.', 0, $e
                );
            }
            if (preg_match('/the uploader has closed their YouTube account/i', $e->getMessage())) {
                throw new YoutubeDlException\ChannelRemovedByUserException(
                    'The channel previously containing the video '.$videoDownload->getVideoId().' has been removed by its user.', 0, $e
                );
            }
            if (preg_match('/who has blocked it on copyright grounds/i', $e->getMessage())) {
                throw new YoutubeDlException\VideoBlockedByCopyrightException(
                    'The video '.$videoDownload->getVideoId().' has been block for copyright infringement.', 0, $e
                );
            }

            throw $e;
        }

        $this->ioHelper->writeln('<info>Done.</info>');
    }

    /**
     * @return string
     * @throws \RuntimeException
     * @throws \Symfony\Component\Filesystem\Exception\IOException
     */
    private function getDownloadsPath(): string
    {
        $projectRoot = Kernel::getProjectRootPath();
        $downloadsPath = str_replace('%project_root%', $projectRoot, $this->options['downloads_paths']['root']['path']);

        // Try to create the downloads root directory... 'cause if it fails, nothing will work.
        (new Filesystem())->mkdir($downloadsPath);

        return rtrim($downloadsPath, DIRECTORY_SEPARATOR);
    }

    /**
     * @param bool $confirmationDefault
     *
     * @return bool
     */
    private function moveAlong($confirmationDefault = true): bool
    {
        // Two reasons to move along: dry-run and declined confirmation.
        if ($this->ioHelper->isDryRun()) {
            $this->ioHelper->writeln('<info>[DRY-RUN]</info> Not doing anything...');

            return true;
        }

        $confirmationQuestion = '<question>Continue?</question> ('.($confirmationDefault ? 'Y/n' : 'y/N').') ';
        if (!$this->ioHelper->askConfirmation($confirmationQuestion, $confirmationDefault)) {
            $this->ioHelper->writeln(PHP_EOL.$this->i().'Move along...');

            return true;
        }

        $this->ioHelper->writeln('');

        return false;
    }

    /**
     * @param int $indentation
     *
     * @return string
     */
    private function i(int $indentation = 1): string
    {
        return str_repeat('  ', $indentation);
    }

    /**
     * @param string $error
     * @param array &$errors
     */
    public function logError(string $error, array &$errors): void
    {
        $this->ioHelper->writeln('<error>An error occurred.</error>');
        $errors[] = $error;
    }

    /**
     * @param array $errors
     * @param string $process
     * @param string $type
     * @param int $indentation
     */
    public function displayErrors(array $errors, string $process, string $type = 'error', int $indentation = 0): void
    {
        $nbErrors = \count($errors);
        if ($nbErrors > 0) {
            $this->ioHelper->forceOutput(function () use ($nbErrors, $errors, $process, $type, $indentation) {
                $this->ioHelper->writeln(
                    PHP_EOL.PHP_EOL.'<'.$type.'>There were '.$nbErrors.' errors during the '.$process.' :</'.$type.'>'
                );

                $this->ioHelper->listing($errors, $indentation);
            });
        }
    }
}
