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
use Symfony\Component\Finder\Finder;

final class YouTube implements Platform
{
    // See https://stackoverflow.com/a/37704433/389519
    private const YOUTUBE_URL_REGEX = <<<REGEX
/\b((?:https?:)?\/\/)?((?:www|m)\.)?((?:youtube\.com|youtu.be))(\/(?:[\w\-]+\?v=|embed\/|v\/)?)([\w\-]+)(\S+)?/i
REGEX;
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

        // Add the platform path part and get a collection of downloads
        $downloads = new Collection();
        foreach ($contents as $content) {
            $content->getPath()->add($platformPathPart);

            foreach ($this->extractDownloads($content) as $download) {
                $downloads->add($download);
            }
        }

        $this->cleanFilesystem($downloads, $downloadPath);
        $this->download($downloads, $downloadPath);
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
     * @param \App\Platform\YouTube\Download $download
     *
     * @return \Symfony\Component\Finder\Finder|\SplFileInfo[]
     * @throws \InvalidArgumentException
     */
    private function getDownloadFolderFinder(Download $download): Finder
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
     * {@inheritdoc}
     * @param \App\Platform\YouTube\Download[]|\App\Domain\Collection $downloads
     *
     * @throws \RuntimeException
     */
    private function cleanFilesystem(Collection $downloads, Path $downloadPath): void
    {
        $this->ui->write(
            sprintf(
                'Synchronize the <info>%s</info> folder with the downloaded contents... ',
                (string) $downloadPath
            )
        );

        $completedDownloadsFolders = new Collection();
        foreach ($downloads as $download) {
            try {
                foreach ($this->getDownloadFolderFinder($download) as $downloadFolder) {
                    $parentFolder = $downloadFolder->getPathInfo();

                    // Using a key ensures there's no duplicates
                    $completedDownloadsFolders->set($parentFolder->getRealPath(), $parentFolder);
                }
            } catch (\InvalidArgumentException $e) {
            }
        }

        /** @var \SplFileInfo[]|\App\Domain\Collection $foldersToRemove */
        $foldersToRemove = new Collection();
        try {
            $allFolders = (new Finder())
                ->directories()
                ->in((string) $downloadPath)
                ->sort(function (\SplFileInfo $fileInfoA, \SplFileInfo $fileInfoB) {
                    // Sort the result by depth
                    $a = substr_count($fileInfoA->getRealPath(), DIRECTORY_SEPARATOR);
                    $b = substr_count($fileInfoB->getRealPath(), DIRECTORY_SEPARATOR);

                    // See http://php.net/manual/en/function.usort.php#example-5942
                    if ($a === $b) {
                        return 0;
                    }

                    return ($a < $b) ? -1 : 1;
                });

            foreach ($allFolders->getIterator() as $folder) {
                if (!$this->isFolderInCollection($folder, $foldersToRemove, true, $downloadPath) &&
                    !$this->isFolderInCollection($folder, $completedDownloadsFolders)
                ) {
                    $foldersToRemove->add($folder);
                }
            }
        } catch (\LogicException $e) {
        }

        $hasRemovedFolders = $this->removeFolders($foldersToRemove, $downloadPath);

        $newLine = $hasRemovedFolders ? PHP_EOL : '';
        $this->ui->writeln($newLine.'<info>Done.</info>'.$newLine);
    }

    /**
     * Checks if a folder (or one of its parent, up to the $limit parameter) is found in the collection of folders.
     *
     * @param \SplFileInfo $folderToSearchFor
     * @param \SplFileInfo[]|\App\Domain\Collection $folders
     * @param bool $loopOverParentsFolders
     * @param Path $untilPath
     *
     * @return bool
     * @throws \RuntimeException
     */
    public function isFolderInCollection(
        \SplFileInfo $folderToSearchFor,
        Collection $folders,
        bool $loopOverParentsFolders = false,
        Path $untilPath = null
    ): bool {
        foreach ($folders as $folder) {
            do {
                // This allows to match "/root/path" in "/root/path" or "/root/path/sub_path"
                if (0 === strpos($folder->getRealPath(), $folderToSearchFor->getRealPath())) {
                    return true;
                }

                if (!$loopOverParentsFolders) {
                    break;
                }
                if (null === $untilPath) {
                    throw new \RuntimeException(
                        'If $loopOverParentsFolders is set to true, then $untilPath must be provided.'.
                        'Otherwise you will experience infinite loops.'
                    );
                }

                $folderToSearchFor = $folderToSearchFor->getPathInfo();

            } while ($folderToSearchFor->getRealPath() !== (string) $untilPath);
        }

        return false;
    }

    /**
     * @param \App\Domain\Collection|\SplFileInfo[] $foldersToRemove
     * @param \App\Domain\Path $downloadPath
     *
     * @return bool Whether folders were removed or not.
     */
    private function removeFolders(Collection $foldersToRemove, Path $downloadPath): bool
    {
        $nbFoldersToRemove = $foldersToRemove->count();
        if (empty($nbFoldersToRemove)) {
            return false;
        }

        $this->ui->writeln(PHP_EOL);

        $confirmationDefault = true;

        // If there's less than 10 folders, we can display them
        if ($nbFoldersToRemove <= 10) {
            $this->ui->writeln(
                sprintf(
                    '%sThe script is about to remove the following folders from <info>%s</info>:',
                    $this->ui->indent(),
                    (string) $downloadPath
                )
            );
            $this->ui->listing(
                $foldersToRemove
                    ->map(function (\SplFileInfo $folder) use ($downloadPath) {
                        return sprintf(
                            '<info>%s</info>',
                            str_replace((string) $downloadPath.DIRECTORY_SEPARATOR, '', $folder->getRealPath())
                        );
                    })
                    ->toArray(),
                3
            );
        } else {
            $confirmationDefault = false;

            $this->ui->write(
                sprintf(
                    '%sThe script is about to remove <question> %s </question> folders from <info>%s</info>. ',
                    $this->ui->indent(),
                    $nbFoldersToRemove,
                    (string) $downloadPath
                )
            );
        }

        $foldersWereRemoved = false;

        $this->ui->write($this->ui->indent());
        if ($this->shouldStopExecution($confirmationDefault)) {
            return $foldersWereRemoved;
        }

        $errors = new Collection();
        foreach ($foldersToRemove as $folderToRemove) {
            $relativeFolderPath = $folderToRemove->getRelativePathname();

            try {
                (new Filesystem())->remove($folderToRemove->getRealPath());

                $foldersWereRemoved = true;

                $this->ui->writeln(
                    sprintf(
                        '%s* The folder <info>%s</info> has been removed.',
                        $this->ui->indent(2),
                        $relativeFolderPath
                    )
                );
            } catch (\Exception $e) {
                $this->logError(
                    sprintf(
                        '%s* <error>The folder %s could not be removed.</error>',
                        $this->ui->indent(2),
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
     * @param \App\Platform\YouTube\Download[]|\App\Domain\Collection $downloads
     * @param \App\Domain\Path $downloadPath
     *
     * @throws \RuntimeException
     */
    private function download(Collection $downloads, Path $downloadPath)
    {
        // Try to create the downloads directory... 'cause if it fails, nothing will work.
        (new Filesystem())->mkdir((string) $downloadPath);

        $this->ui->writeln('Download files from YouTube... '.PHP_EOL);

        // Filter out downloads that have already been downloaded
        /** @var \App\Platform\YouTube\Download[]|\App\Domain\Collection $downloads */
        $downloads = $downloads->filter(
            function (Download $download) {
                $shouldBeDownloaded = true;
                try {
                    if ($this->getDownloadFolderFinder($download)->hasResults()) {
                        $shouldBeDownloaded = false;
                    }
                } catch (\InvalidArgumentException $e) {
                }

                return $shouldBeDownloaded;
            }
        );

        if ($downloads->isEmpty()) {
            $this->ui->writeln($this->ui->indent().'<comment>Nothing to download.</comment>'.PHP_EOL);

            return;
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
        if ($this->shouldStopExecution()) {
            $this->ui->writeln(PHP_EOL.'<info>Done.</info>'.PHP_EOL);

            return;
        }

        $errors = new Collection();
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

    /**
     * @param bool $confirmationDefault
     *
     * @return bool
     */
    private function shouldStopExecution($confirmationDefault = true): bool
    {
        // Two reasons to move along: dry-run and declined confirmation.
        if ($this->dryRun) {
            $this->ui->writeln('<info>[DRY-RUN]</info> Not doing anything...'.PHP_EOL);

            return true;
        }

        $confirmationQuestion = '<question>Continue?</question> ('.($confirmationDefault ? 'Y/n' : 'y/N').') ';
        if (!$this->ui->askConfirmation($confirmationQuestion, $confirmationDefault)) {
            $this->ui->writeln(PHP_EOL.$this->ui->indent().'Not doing anything...');

            return true;
        }

        return false;
    }

    /**
     * @param string $error
     * @param \App\Domain\Collection $errors
     */
    public function logError(string $error, Collection $errors): void
    {
        $this->ui->writeln('<error>An error occurred.</error>');
        $errors->add($error);
    }

    /**
     * @param \App\Domain\Collection $errors
     * @param string $process
     * @param string $type
     * @param int $indentation
     */
    public function displayErrors(
        Collection $errors,
        string $process,
        string $type = 'error',
        int $indentation = 0
    ): void {
        $nbErrors = $errors->count();
        if ($nbErrors > 0) {
            $this->ui->forceOutput(function () use ($nbErrors, $errors, $process, $type, $indentation) {
                $this->ui->writeln(
                    PHP_EOL.PHP_EOL.'<'.$type.'>There were '.$nbErrors.' errors during the '.$process.' :</'.$type.'>'
                );

                $this->ui->listing($errors->toArray(), $indentation);
            });
        }
    }
}
