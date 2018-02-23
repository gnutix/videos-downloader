<?php declare(strict_types=1);

namespace App\Filesystem;

use App\Domain\Path;
use App\UI\Skippable;
use App\UI\UserInterface;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Finder\Finder;

final class FilesystemCleaner
{
    use Skippable;

    /** @var \App\UI\UserInterface */
    private $ui;

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
     * @throws \RuntimeException
     */
    public function __invoke(Path $downloadPath): void
    {
        $foldersToRemove = $this->getFoldersToRemove($downloadPath);

        if ($this->shouldRemoveFolders($foldersToRemove, $downloadPath)) {
            $this->removeFolders($foldersToRemove);
        }
    }

    /**
     * @param \App\Domain\Path $downloadPath
     *
     * @return \App\Filesystem\FilesystemObjects
     * @throws \RuntimeException
     */
    private function getFoldersToRemove(Path $downloadPath): FilesystemObjects
    {
        $foldersToRemove = new FilesystemObjects();
        try {
            // Look for empty folders and remove these
            $emptyFolders = $this->getDownloadFoldersFinder($downloadPath)
                ->filter(function (\SplFileInfo $folder) {
                    return !(new Finder())->files()->in($folder->getRealPath())->hasResults();
                });

            foreach ($emptyFolders->getIterator() as $folder) {
                $foldersToRemove->set($folder->getRealPath(), $folder);
            }
        } catch (\LogicException $e) {
            // Here we know that the download folder will exist.
        }

        return $foldersToRemove;
    }

    /**
     * @param \App\Domain\Path $downloadPath
     *
     * @return \Symfony\Component\Finder\Finder
     * @throws \InvalidArgumentException
     */
    private function getDownloadFoldersFinder(Path $downloadPath): Finder
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
     * @param \App\Filesystem\FilesystemObjects $foldersToRemove
     */
    private function removeFolders(FilesystemObjects $foldersToRemove): void
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
     * @param \App\Filesystem\FilesystemObjects $foldersToRemove
     * @param \App\Domain\Path $downloadPath
     *
     * @return bool
     */
    private function shouldRemoveFolders(FilesystemObjects $foldersToRemove, Path $downloadPath): bool
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
