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
    const YOUTUBE_URL_REGEX = '/\b((?:https?:)?\/\/)?((?:www|m)\.)?((?:youtube\.com|youtu.be))(\/(?:[\w\-]+\?v=|embed\/|v\/)?)([\w\-]+)(\S+)?/i';
    const YOUTUBE_URL_REGEX_MATCHES_ID_INDEX = 5;
    const YOUTUBE_URL_PREFIX = 'https://www.youtube.com/watch?v=';

    /** @var IOHelper */
    private $ioHelper;

    /** @var array */
    private $options;

    /**
     * {@inheritdoc}
     * @throws \Symfony\Component\Filesystem\Exception\IOException
     */
    public function __construct(IOHelper $ioHelper, array $options)
    {
        $this->ioHelper = $ioHelper;
        $this->options = $options;

        // Try to create the downloads root directory... 'cause if it fails, nothing will work.
        (new Filesystem())->mkdir($this->getDestinationPath());
    }

    /**
     * {@inheritdoc}
     */
    public function extractVideosIds($input): array
    {
        if (preg_match_all(static::YOUTUBE_URL_REGEX, $input, $youtubeUrls)) {
            $videoIds = [];

            foreach ((array) $youtubeUrls[static::YOUTUBE_URL_REGEX_MATCHES_ID_INDEX] as $youtubeId) {
                foreach ((array) $this->options['download_files'] as $type => $extension) {
                    $videoIds[$type][$youtubeId] = $youtubeId;
                }
            }

            return $videoIds;
        }

        return [];
    }

    /**
     * {@inheritdoc}
     */
    public function downloadVideos(array $videoDownloads)
    {
        $videoDownloads = $this->prepareVideosForDownload($videoDownloads);

        $this->downloadVideosFromYouTube($videoDownloads);
    }

    /**
     * @param \App\Domain\VideoDownload[] $videoDownloads
     *
     * @return array
     */
    private function prepareVideosForDownload(array $videoDownloads): array
    {
        $this->ioHelper->write('Skip the videos having their files already downloaded...');

        /** @var \SplFileInfo[] $completedVideoDownloadsFolders */
        $completedVideoDownloadsFolders = [];

        foreach ($videoDownloads as &$videoDownload) {

            /** @var \Symfony\Component\Finder\Finder $videoDownloadFolderFinder */
            try {
                $videoDownloadFolderFinder = (new Finder())
                    ->files()
                    ->depth('== 0')
                    ->in(
                        sprintf(
                            '%s'.DIRECTORY_SEPARATOR.'%s'.DIRECTORY_SEPARATOR.'%s*',
                            $this->getDestinationPath(),
                            $videoDownload->getPath(),
                            $videoDownload->getVideoId()
                        )
                    )
                    ->name('*.'.$this->options['download_files'][$videoDownload->getType()]);

                foreach ($videoDownloadFolderFinder as $videoDownloadFolder) {
                    unset($videoDownloads[(string) $videoDownload->getId()]);

                    // Using a key ensures there's no duplicates
                    $parentFolder = $videoDownloadFolder->getPathInfo();
                    $completedVideoDownloadsFolders[$parentFolder->getRealPath()] = $parentFolder;
                }
            } catch (\Exception $e) {
                // The folder simply does not exist yet
            }
        }
        unset($videoDownload);

        $this->ioHelper->done();

        $this->ioHelper->write(
            sprintf(
                'Synchronize the <info>%s</info> folder with the videos from the source...',
                $this->getDestinationPath()
            )
        );

        /** @var \Symfony\Component\Finder\Finder $foldersToRemoveFinder */
        $foldersToRemoveFinder = (new Finder())
            ->directories()
            ->in($this->getDestinationPath())
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
                    } while ($path->getRealPath() !== $this->getDestinationPath());
                }

                return true;
            });
        $hasFoldersToRemove = $foldersToRemoveFinder->hasResults();

        if ($hasFoldersToRemove) {
            $this->ioHelper->write(PHP_EOL, true);

            // Here we need to use iterator_to_array(), otherwise removing the folder messes up the internal Iterator
            foreach (iterator_to_array($foldersToRemoveFinder) as $folderToRemove) {
                $relativeFolderPath = $folderToRemove->getRelativePathname();

                try {
                    (new Filesystem())->remove($folderToRemove->getRealPath());

                    $this->ioHelper->write(
                        sprintf('  * The folder <info>%s</info> has been removed.', $relativeFolderPath),
                        true
                    );
                } catch (\Exception $e) {
                    $this->ioHelper->write(
                        sprintf('  * <error>The folder %s could not be removed.</error>', $relativeFolderPath),
                        true
                    );
                }
            }

            $this->ioHelper->write('', true);
        }

        $this->ioHelper->done();

        if ($hasFoldersToRemove) {
            $this->ioHelper->write('', true);
        }

        return $videoDownloads;
    }

    /**
     * @param \App\Domain\VideoDownload[] $videoDownloads
     */
    private function downloadVideosFromYouTube(array $videoDownloads)
    {
        $this->ioHelper->write('Download videos from YouTube...'.PHP_EOL, true);

        if (empty($videoDownloads)) {
            $this->ioHelper->write( '<comment>Nothing to download.</comment>', true);

            return;
        }

        $errors = [];

        foreach ($videoDownloads as $videoDownload) {
            $this->ioHelper->write(
                sprintf(
                    '  * [<comment>%s</comment>][<comment>%s</comment>] Download the %s file in <info>%s</info>...',
                    $videoDownload->getVideoId(),
                    $videoDownload->getType(),
                    $this->options['download_files'][$videoDownload->getType()],
                    $videoDownload->getPath()
                )
            );

            $attempts = 0;
            $maxAttempts = 5;
            while (true) {
                try {
                    $this->downloadFromYouTube($videoDownload);
                    break;
                } catch (YoutubeDlException\CustomYoutubeDlException $e) {
                    $this->ioHelper->logError($e, $errors);
                    break;
                } catch (\Exception $e) {
                    $attempts++;
                    sleep(2);
                    if ($attempts >= $maxAttempts) {
                        $this->ioHelper->logError($e, $errors);
                        break;
                    }
                    continue;
                }
            }
        }

        $this->ioHelper->displayErrors($errors, 'download of files');

        $this->ioHelper->done(1);
    }

    /**
     * @param VideoDownload $videoDownload
     *
     * @throws \Exception
     */
    private function downloadFromYouTube(VideoDownload $videoDownload)
    {
        $path = $this->getDestinationPath().DIRECTORY_SEPARATOR.$videoDownload->getPath();

        $dl = new YoutubeDl($this->options['youtube_dl_options'][$videoDownload->getType()]);
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

        $this->ioHelper->done();
    }

    /**
     * @return string
     */
    private function getDestinationPath(): string
    {
        return sprintf(
            '%s'.DIRECTORY_SEPARATOR.'%s',
            rtrim(Kernel::getProjectRootPath(), DIRECTORY_SEPARATOR),
            trim($this->options['destination']['path_root'], DIRECTORY_SEPARATOR)
        );
    }
}
