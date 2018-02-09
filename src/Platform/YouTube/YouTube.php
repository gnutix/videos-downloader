<?php declare(strict_types=1);

namespace App\Platform\YouTube;

use App\Domain\Content;
use App\Platform\Platform;
use App\UI\UserInterface;
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

    /** @var \App\UI\UserInterface */
    private $ui;

    /** @var array */
    private $options;

    /** @var string */
    private $dryRun;

    /**
     * {@inheritdoc}
     * @throws \RuntimeException
     * @throws \Symfony\Component\Filesystem\Exception\IOException
     */
    public function __construct(UserInterface $ui, array $options, bool $dryRun = false)
    {
        $this->ui = $ui;
        $this->options = $options;
        $this->dryRun = $dryRun;
    }

    /**
     * {@inheritdoc}
     * @param \App\Domain\Content[] $contents
     * @throws \RuntimeException
     */
    public function downloadContents(array $contents, string $rootPathPart): void
    {
        if (empty($contents)) {
            return;
        }

        // Prepend the platform and root path parts
        $downloads = [];
        foreach ($contents as $content) {
            $content->addPathPart($this->options['paths']['path_part'], Content::PATH_PART_PREPEND);
            $content->addPathPart($rootPathPart, Content::PATH_PART_PREPEND);

            foreach ($this->extractDownloads($content) as $download) {
                $downloads[] = $download;
            }
        }

        // This is a bit of a code hack... but it works :)
        $downloadPath = (new Content('', $rootPathPart))->addPathPart($this->options['paths']['path_part'])->getPath();

        // Try to create the downloads directory... 'cause if it fails, nothing will work.
        (new Filesystem())->mkdir($downloadPath);

        $downloads = $this->prepareForDownload($downloads, $downloadPath);

        $this->download($downloads, $downloadPath);
    }

    /**
     * {@inheritdoc}
     */
    public function extractDownloads(Content $content): array
    {
        if (!preg_match_all(static::YOUTUBE_URL_REGEX, $content->getData(), $youtubeUrls)) {
            return [];
        }

        $downloads = [];
        foreach ((array) $youtubeUrls[static::YOUTUBE_URL_REGEX_MATCHES_ID_INDEX] as $youtubeId) {
            foreach (array_keys($this->options['youtube_dl']['options']) as $videoFileType) {
                $downloads[] = new Download(
                    $videoFileType,
                    $this->options['paths']['video_files']['extensions'][$videoFileType],
                    $youtubeId,
                    $content->getPath()
                );
            }
        }

        return $downloads;
    }

    /**
     * @param \App\Platform\YouTube\Download[] $downloads
     * @param string $downloadPath
     *
     * @return array
     */
    private function prepareForDownload(array $downloads, string $downloadPath): array
    {
        $this->ui->write('Skip the contents having their files already downloaded... ');

        /** @var \SplFileInfo[] $completedDownloadsFolders */
        $completedDownloadsFolders = [];

        // Passing the value by reference prevents PHP from creating a copy of the array
        foreach ($downloads as $downloadKey => &$download) {
            $placeholders = [
                '%video_id%' => $download->getVideoId(),
                '%file_extension%' => $download->getFileExtension(),
            ];

            /** @var \Symfony\Component\Finder\Finder $downloadFolderFinder */
            try {
                $downloadFolderFinder = (new Finder())
                    ->files()
                    ->depth('== 0')
                    ->in(
                        implode(
                            DIRECTORY_SEPARATOR,
                            [
                                $download->getPath(),
                                str_replace(
                                    array_keys($placeholders),
                                    array_values($placeholders),
                                    $this->options['paths']['video_files']['path_part']
                                ),
                            ]
                        )
                    )
                    ->name(
                        str_replace(
                            array_keys($placeholders),
                            array_values($placeholders),
                            $this->options['paths']['video_files']['filename']
                        )
                    );

                foreach ($downloadFolderFinder as $downloadFolder) {
                    unset($downloads[$downloadKey]);

                    $parentFolder = $downloadFolder->getPathInfo();

                    // Using a key ensures there's no duplicates
                    $completedDownloadsFolders[$parentFolder->getRealPath()] = $parentFolder;
                }
            } catch (\InvalidArgumentException $e) {
                // This is not the folder you're looking for. You can go about your business. Move along, move along...
            }
        }
        unset($download); // See https://alephnull.uk/call-unset-after-php-foreach-loop-values-passed-by-reference

        $this->ui->writeln('<info>Done.</info>');
        $this->ui->write(
            sprintf(
                'Synchronize the <info>%s</info> folder with the downloaded contents... ',
                $downloadPath
            )
        );

        // Here we can skip this inspection as we know the folder exists.
        /** @noinspection ExceptionsAnnotatingAndHandlingInspection */
        $this->removeFolders(
            iterator_to_array(
                (new Finder())
                    ->directories()
                    ->in($downloadPath)
                    ->filter(function(\SplFileInfo $folder) use ($downloadPath, $completedDownloadsFolders) {

                        // Try exact folder matching
                        if (isset($completedDownloadsFolders[$folder->getRealPath()])) {
                            return false;
                        }

                        // Try recursive parent folder matching
                        foreach ($completedDownloadsFolders as $path) {
                            do {
                                // Get the parent folder
                                $path = $path->getPathInfo();

                                if ($path->getRealPath() === $folder->getRealPath()) {
                                    return false;
                                }

                                // Until we reach the downloads root folder
                            } while ($path->getRealPath() !== $downloadPath);
                        }

                        return true;
                    })
            ),
            $downloadPath
        );

        $this->ui->writeln(PHP_EOL.'<info>Done.</info>'.PHP_EOL);

        return $downloads;
    }

    /**
     * @param array $foldersToRemove
     * @param string $downloadPath
     *
     * @return bool Whether folders were removed or not.
     */
    private function removeFolders(array $foldersToRemove, string $downloadPath): bool
    {
        if (empty($foldersToRemove)) {
            return false;
        }

        $this->ui->writeln(PHP_EOL);

        $confirmationDefault = true;
        $nbFoldersToRemove = \count($foldersToRemove);

        // If there's less than 10 folders, we can display them
        if ($nbFoldersToRemove <= 10) {
            $this->ui->writeln(
                sprintf(
                    $this->i().'The script is about to remove the following folders from <info>%s</info>:',
                    $downloadPath
                )
            );
            $this->ui->listing(
                array_map(
                    function (string $path) use ($downloadPath) {
                        return '<info>'.str_replace($downloadPath.DIRECTORY_SEPARATOR, '', $path).'</info>';
                    },
                    $foldersToRemove
                ),
                3
            );
        } else {
            $confirmationDefault = false;

            $this->ui->write(
                sprintf(
                    $this->i().'The script is about to remove <question> %s </question> folders from <info>%s</info>. ',
                    $nbFoldersToRemove,
                    $downloadPath
                )
            );
        }

        $foldersWereRemoved = false;

        $this->ui->write($this->i());
        if ($this->moveAlong($confirmationDefault)) {
            return $foldersWereRemoved;
        }

        $errors = [];
        foreach ($foldersToRemove as $folderToRemove) {
            $relativeFolderPath = $folderToRemove->getRelativePathname();

            try {
                (new Filesystem())->remove($folderToRemove->getRealPath());

                $foldersWereRemoved = true;

                $this->ui->writeln(
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
     * @param \App\Platform\YouTube\Download[] $downloads
     * @param string $downloadPath
     *
     * @throws \RuntimeException
     */
    private function download(array $downloads, string $downloadPath)
    {
        $this->ui->writeln('Download files from YouTube... '.PHP_EOL);

        if (empty($downloads)) {
            $this->ui->writeln('<comment>Nothing to download.</comment>');

            return;
        }

        $this->ui->writeln(
            sprintf(
                $this->i().'The script is about to download <question> %s </question> files into <info>%s</info>. '.PHP_EOL,
                \count($downloads),
                $downloadPath
            )
        );

        $this->ui->write($this->i());
        if ($this->moveAlong()) {
            $this->ui->writeln(PHP_EOL.'<info>Done.</info>'.PHP_EOL);

            return;
        }

        $errors = [];
        foreach ($downloads as $download) {
            $this->ui->write(
                sprintf(
                    '%s* [<comment>%s</comment>][<comment>%s</comment>] Download the %s file in <info>%s</info>... ',
                    str_repeat($this->i(), 2),
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
        $dl->setDownloadPath($download->getPath());

        try {
            (new Filesystem())->mkdir($download->getPath());
            $dl->download(static::YOUTUBE_URL_PREFIX.$download->getVideoId());
        } catch (\Exception $e) {

            // Add more custom exceptions than those already provided by YoutubeDl
            if (preg_match('/this video is unavailable/i', $e->getMessage())) {
                throw new YoutubeDlException\VideoUnavailableException(
                    'The video '.$download->getVideoId().' is unavailable.', 0, $e
                );
            }
            if (preg_match('/this video has been removed by the user/i', $e->getMessage())) {
                throw new YoutubeDlException\VideoRemovedByUserException(
                    'The video '.$download->getVideoId().' has been removed by its user.', 0, $e
                );
            }
            if (preg_match('/the uploader has closed their YouTube account/i', $e->getMessage())) {
                throw new YoutubeDlException\ChannelRemovedByUserException(
                    'The channel previously containing the video '.$download->getVideoId().' has been removed by its user.', 0, $e
                );
            }
            if (preg_match('/who has blocked it on copyright grounds/i', $e->getMessage())) {
                throw new YoutubeDlException\VideoBlockedByCopyrightException(
                    'The video '.$download->getVideoId().' has been block for copyright infringement.', 0, $e
                );
            }

            throw $e;
        }
    }

    /**
     * @param bool $confirmationDefault
     *
     * @return bool
     */
    private function moveAlong($confirmationDefault = true): bool
    {
        // Two reasons to move along: dry-run and declined confirmation.
        if ($this->dryRun) {
            $this->ui->writeln('<info>[DRY-RUN]</info> Not doing anything...');

            return true;
        }

        $confirmationQuestion = '<question>Continue?</question> ('.($confirmationDefault ? 'Y/n' : 'y/N').') ';
        if (!$this->ui->askConfirmation($confirmationQuestion, $confirmationDefault)) {
            $this->ui->writeln(PHP_EOL.$this->i().'Move along...');

            return true;
        }

        $this->ui->writeln('');

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
        $this->ui->writeln('<error>An error occurred.</error>');
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
            $this->ui->forceOutput(function () use ($nbErrors, $errors, $process, $type, $indentation) {
                $this->ui->writeln(
                    PHP_EOL.PHP_EOL.'<'.$type.'>There were '.$nbErrors.' errors during the '.$process.' :</'.$type.'>'
                );

                $this->ui->listing($errors, $indentation);
            });
        }
    }
}
