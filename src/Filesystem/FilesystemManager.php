<?php declare(strict_types=1);

namespace App\Filesystem;

use App\Domain\Downloads;
use App\Domain\Path;
use App\Domain\Download;
use App\UI\Skippable;
use App\UI\UserInterface;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Finder\Finder;

abstract class FilesystemManager
{
    use Skippable;

    /** @var \App\UI\UserInterface */
    protected $ui;

    /**
     * @param \App\UI\UserInterface $ui
     */
    public function __construct(UserInterface $ui)
    {
        $this->ui = $ui;
    }

    /**
     * @param \App\Domain\Path $downloadPath
     *
     * @return \Symfony\Component\Finder\Finder
     * @throws \InvalidArgumentException
     */
    protected function getAllDownloadsFolderFinder(Path $downloadPath): Finder
    {
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
     * @param \App\Domain\Download $download
     *
     * @return string
     */
    abstract protected function getDownloadFolder(Download $download): string;

    /**
     * @param \App\Domain\Download $download
     *
     * @return \Symfony\Component\Finder\Finder|\SplFileInfo[]
     * @throws \InvalidArgumentException
     */
    protected function getDownloadFolderFinder(Download $download): Finder
    {
        return (new Finder())
            ->files()
            ->depth('== 0')
            ->in($this->getDownloadFolder($download));
    }

    /**
     * {@inheritdoc}
     * @param \App\Domain\Downloads $downloads
     *
     * @throws \RuntimeException
     */
    protected function cleanFilesystem(Downloads $downloads, Path $downloadPath): void
    {
        $foldersToRemove = $this->getFoldersToRemove($downloads, $downloadPath);

        if ($this->shouldRemoveFolders($foldersToRemove, $downloadPath)) {
            $this->removeFolders($foldersToRemove);
        }
    }

    /**
     * @param \App\Domain\Downloads $downloads
     * @param \App\Domain\Path $downloadPath
     *
     * @return \App\Filesystem\FilesystemObjects
     * @throws \RuntimeException
     */
    protected function getFoldersToRemove(Downloads $downloads, Path $downloadPath): FilesystemObjects
    {
        $foldersToRemove = new FilesystemObjects();
        try {
            $completedDownloadsFolders = $this->getCompletedDownloadsFolders($downloads);

            foreach ($this->getAllDownloadsFolderFinder($downloadPath)->getIterator() as $folder) {

                // If the folder has already been tagged for removal, we skip it
                if ($this->isFolderInCollection($folder, $foldersToRemove, true, $downloadPath)) {
                    continue;
                }

                // If the folder contains downloaded files, we skip it
                if ($this->isFolderInCollection($folder, $completedDownloadsFolders)) {
                    continue;
                }

                $foldersToRemove->add($folder);
            }
        } catch (\LogicException $e) {
            // Here we know that the download folder will exist.
        }

        return $foldersToRemove;
    }

    /**
     * Checks if a folder (or one of its parent, up to the $limit parameter) is found in the collection of folders.
     *
     * @param \SplFileInfo $folderToSearchFor
     * @param \App\Filesystem\FilesystemObjects $folders
     * @param bool $loopOverParentsFolders
     * @param \App\Domain\Path $untilPath
     *
     * @return bool
     * @throws \RuntimeException
     */
    protected function isFolderInCollection(
        \SplFileInfo $folderToSearchFor,
        FilesystemObjects $folders,
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
     * @param \App\Filesystem\FilesystemObjects $foldersToRemove
     */
    protected function removeFolders(FilesystemObjects $foldersToRemove): void
    {
        $errors = [];
        foreach ($foldersToRemove as $folderToRemove) {
            $relativeFolderPath = $folderToRemove->getRelativePathname();

            try {
                (new Filesystem())->remove($folderToRemove->getRealPath());

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

        $this->ui->writeln(PHP_EOL.'<info>Done.</info>'.PHP_EOL);
    }

    /**
     * @param \App\Domain\Downloads $downloads
     *
     * @return \App\Filesystem\FilesystemObjects
     */
    protected function getCompletedDownloadsFolders(Downloads $downloads): FilesystemObjects
    {
        $completedDownloadsFolders = new FilesystemObjects();
        foreach ($downloads as $download) {
            try {
                foreach ($this->getDownloadFolderFinder($download) as $downloadFolder) {
                    $completedDownloadsFolders->add($downloadFolder->getPathInfo());
                }
            } catch (\InvalidArgumentException $e) {
            }
        }

        return $completedDownloadsFolders;
    }

    /**
     * @param \App\Filesystem\FilesystemObjects $foldersToRemove
     * @param \App\Domain\Path $downloadPath
     *
     * @return bool
     */
    protected function shouldRemoveFolders(FilesystemObjects $foldersToRemove, Path $downloadPath): bool
    {
        $this->ui->write(
            sprintf(
                'Synchronize the <info>%s</info> folder with the downloaded contents... ',
                (string) $downloadPath
            )
        );

        if ($foldersToRemove->isEmpty()) {
            $this->ui->writeln('<info>Done.</info>');

            return false;
        }

        $this->ui->writeln(PHP_EOL);

        if (!$this->ui->isDryRun() && !$this->ui->isInteractive()) {
            return true;
        }

        $confirmationDefault = true;

        // If there's less than 10 folders, we can display them
        $nbFoldersToRemove = $foldersToRemove->count();
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

        if ($this->skip($this->ui) || !$this->ui->confirm($confirmationDefault)) {
            $this->ui->writeln(($this->ui->isDryRun() ? '' : PHP_EOL).'<info>Done.</info>'.PHP_EOL);

            return false;
        }

        return true;
    }
}
