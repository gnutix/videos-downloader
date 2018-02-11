<?php declare(strict_types=1);

namespace App\Platform\YouTube;

use App\Domain\Collection;
use App\Domain\Path;
use App\Domain\PathPart;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Finder\Finder;

trait FilesystemManager
{
    use DryRunner;

    /** @var array */
    private $options;

    /**
     * @param \App\Domain\Path $downloadPath
     *
     * @return \Symfony\Component\Finder\Finder
     */
    private function getAllDownloadsFolderFinder(Path $downloadPath): Finder
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

        $foldersToRemove = new Collection();
        try {
            $completedDownloadsFolders = $this->getCompletedDownloadsFolders($downloads);

            foreach ($this->getAllDownloadsFolderFinder($downloadPath)->getIterator() as $folder) {
                if (!$this->isFolderInCollection($folder, $foldersToRemove, true, $downloadPath) &&
                    !$this->isFolderInCollection($folder, $completedDownloadsFolders)
                ) {
                    $foldersToRemove->add($folder);
                }
            }
        } catch (\LogicException $e) {
            // Here we know that the download folder will exist.
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
    private function isFolderInCollection(
        \SplFileInfo $folderToSearchFor,
        Collection $folders,
        bool $loopOverParentsFolders = false,
        ?Path $untilPath = null
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
     * @param \SplFileInfo[]|\App\Domain\Collection $foldersToRemove
     * @param \App\Domain\Path $downloadPath
     *
     * @return bool Whether folders were removed or not.
     */
    private function removeFolders(Collection $foldersToRemove, Path $downloadPath): bool
    {
        $foldersWereRemoved = false;

        if (!$this->shouldRemoveFolders($foldersToRemove, $downloadPath)) {
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
                        $this->ui->indent(2),
                        $relativeFolderPath
                    )
                );
            } catch (\Exception $e) {
                $this->ui->logError(
                    sprintf(
                        '%s* <error>The folder %s could not be removed.</error>',
                        $this->ui->indent(2),
                        $relativeFolderPath
                    ),
                    $errors
                );
            }
        }
        $this->ui->displayErrors($errors, 'the removal of folders', 'info', 1);

        return $foldersWereRemoved;
    }

    /**
     * @param \App\Platform\YouTube\Download[]|\App\Domain\Collection $downloads
     *
     * @return \SplFileInfo[]|\App\Domain\Collection
     */
    private function getCompletedDownloadsFolders(Collection $downloads): Collection
    {
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

        return $completedDownloadsFolders;
    }

    /**
     * @param \SplFileInfo[]|\App\Domain\Collection $foldersToRemove
     * @param \App\Domain\Path $downloadPath
     *
     * @return bool
     */
    private function shouldRemoveFolders(Collection $foldersToRemove, Path $downloadPath): bool
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

        $this->ui->write($this->ui->indent());

        return !($this->skip() || !$this->ui->confirm($confirmationDefault));
    }
}
