<?php declare(strict_types=1);

namespace App;

use App\YoutubeDl\Exception\VideoRemovedByUserException;
use App\YoutubeDl\Exception\VideoUnavailableException;
use Stevenmaguire\Services\Trello\Client;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Helper\ProgressBar;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Finder\Finder;
use App\YoutubeDl\YoutubeDl;

final class RepertoireDownloader extends Command
{
    const DOWNLOADS_PATH = __DIR__.DIRECTORY_SEPARATOR.'..'.DIRECTORY_SEPARATOR.'downloads'.DIRECTORY_SEPARATOR;

    const TRELLO_API_KEY = 'beb1cf49bfceca0ea4f7dc063fe54f37'; // fetched from https://trello.com/app-key
    const TRELLO_REPERTOIRE_BOARD_ID = 'MkYHGxzY'; // full URL is https://trello.com/b/MkYHGxzY/repertoire
    const TRELLO_IMPROVISATIONS_LIST_ID = '588b79626416a37b42587e3b';

    // See https://stackoverflow.com/a/37704433/389519
    const YOUTUBE_URL_REGEX = '#\b((?:https?:)?\/\/)?((?:www|m)\.)?((?:youtube\.com|youtu.be))(\/(?:[\w\-]+\?v=|embed\/|v\/)?)([\w\-]+)(\S+)?#i';
    const YOUTUBE_URL_PREFIX = 'https://www.youtube.com/watch?v=';
    const YOUTUBE_ID_LENGTH = 11;
    const YOUTUBE_FOLDER_REGEX_SUFFIX = ' \((?:.*), [0-9]{8}\)$';
    const YOUTUBE_FOLDER_REGEX = '#^(?:[\w\-\_]{'.self::YOUTUBE_ID_LENGTH.'})'.self::YOUTUBE_FOLDER_REGEX_SUFFIX.'#ui';

    /** @var InputInterface */
    private $input;

    /** @var OutputInterface */
    private $output;

    /**
     * {@inheritdoc}
     * @throws \LogicException
     */
    public function initialize(InputInterface $input, OutputInterface $output)
    {
        $this->input = $input;
        $this->output = $output;
    }

    /**
     * {@inheritdoc}
     * @throws \Symfony\Component\Filesystem\Exception\IOException
     */
    public function execute(InputInterface $input, OutputInterface $output)
    {
        $filesystem = new Filesystem();
        if (!$filesystem->exists(static::DOWNLOADS_PATH)) {
            $this->start('Create the downloads folder...');
            $filesystem->mkdir(static::DOWNLOADS_PATH);
            $this->done();
        }

        $this->start('Fetch the songs information from Trello...');
        $songs = $this->getSongsFromTrello();
        $this->done();

        $this->start('Prepare the system for the download of the songs...');
        $songs = $this->prepareForSongsDownload($songs);
        $this->done();

        $this->start('Download songs from YouTube...', 2);
        $this->downloadSongsFromYouTube($songs);
        $this->done(1);

        $this->output->write(PHP_EOL);
    }

    /**
     * @return \App\Song[]
     */
    private function getSongsFromTrello(): array
    {
        /** @var \App\PhpDoc\TrelloClient $trello */
        $trello = new Client(['token' => static::TRELLO_API_KEY]);
        $trelloLists = $trello->getBoardLists(static::TRELLO_REPERTOIRE_BOARD_ID);
        $trelloCards = $trello->getBoardCards(static::TRELLO_REPERTOIRE_BOARD_ID);

        // Add the list ID as a key to the array so it's easier to access below
        $lists = array_combine(array_column($trelloLists, 'id'), $trelloLists);

        // Look for YouTube URLs in the description
        $songs = [];
        foreach ($trelloCards as $card) {
            if (preg_match_all(static::YOUTUBE_URL_REGEX, $card->desc, $youtubeUrls)) {
                $songs[$card->id] = new Song($youtubeUrls[5], $lists[$card->idList]->name, $card->name);
            }
        }

        return $songs;
    }

    /**
     * @param \App\Song[] $songs
     *
     * @return array
     */
    private function prepareForSongsDownload(array $songs): array
    {
        $errors = [];

        // Prepare a flat list of all YouTube IDs
        $youtubeIdsAsKeys = [];
        foreach ($songs as $songId => $song) {
            foreach ($song->getYoutubeIds() as $youtubeId) {
                $youtubeIdsAsKeys[$youtubeId] = $songId;
            }
        }

        try {
            /** @var Finder $folders */
            $folders = (new Finder())
                ->directories()
                ->in(static::DOWNLOADS_PATH)
                ->name(static::YOUTUBE_FOLDER_REGEX);

            foreach ($folders as $folder) {
                $youtubeId = substr($folder->getBasename(), 0, static::YOUTUBE_ID_LENGTH);
                $songId = $youtubeIdsAsKeys[$youtubeId] ?? null;

                // If the ID isn't in the list of Trello songs, we need to remove that folder (as it's "deprecated")
                if (!$songId) {

                    // Go through the parent folders as long as they only contain one child
                    $folderToRemove = $folder;
                    $parentFolder = $folder->getPathInfo();
                    while (1 === ($count = (new Finder())->in($parentFolder->getRealPath())->depth('== 0')->count())) {
                        $folderToRemove = $parentFolder;
                        $parentFolder = $parentFolder->getPathInfo();
                    }

                    (new Filesystem())->remove($folderToRemove->getRealPath());

                    $this->logError(
                        new \Exception(sprintf('The folder "%s" has been removed.', $folderToRemove->getRealPath())),
                        $errors,
                        'info'
                    );

                // If the ID is in Trello songs but has been downloaded already, we remove it
                } else if ($songs[$songId]) {
                    $songs[$songId]->removeYoutubeId($youtubeId);
                }
            }

        } catch (\Exception $e) {
            $this->logError($e, $errors);
        }

        $this->displayErrors($errors);

        // Ensure we don't process songs that have no youtube IDs
        return array_filter($songs, function (Song $song) {
            return !empty($song->getYoutubeIds());
        });
    }

    /**
     * @param \App\Song[] $songs
     */
    private function downloadSongsFromYouTube(array $songs)
    {
        if (empty($songs)) {
            $this->output->writeln( '<comment>Nothing to download.</comment>');

            return;
        }

        $errors = [];

        foreach ($songs as $song) {
            $this->start('Download the audio(s) for "'.$song->getName().'"...', 1);

            $progressBar = new ProgressBar($this->output, \count($song->getYoutubeIds()));
            $progressBar->setMessage('Download the audio(s) for "'.$song->getName().'"...');
            $progressBar->start();

            foreach ($song->getYoutubeIds() as $youtubeId) {
                $attempts = 0;
                $maxAttempts = 5;
                while (true) {
                    try {
                        $this->downloadSongFromYouTube(static::DOWNLOADS_PATH.$song->getPath(), $youtubeId);
                        break;
                    } catch (VideoRemovedByUserException $e) {
                        $this->logError($e, $errors);
                        break;
                    } catch (VideoUnavailableException $e) {
                        $this->logError($e, $errors);
                        break;
                    } catch (\Exception $e) {
                        $attempts++;
                        sleep(2);
                        if ($attempts >= $maxAttempts) {
                            $this->logError($e, $errors);
                            break;
                        }
                        continue;
                    }
                }

                $progressBar->advance();
            }
            $progressBar->finish();
            $this->done();
        }

        $this->displayErrors($errors);
    }

    /**
     * @param string $path
     * @param string $youtubeId
     *
     * @throws \Exception
     */
    private function downloadSongFromYouTube($path, $youtubeId)
    {
        $filesystem = new Filesystem();

        if (!$filesystem->exists($path)) {
            $filesystem->mkdir($path);
        }

        $downloadedAlready = (new Finder())
            ->directories()
            ->in($path)
            ->depth('== 0')
            ->name('#^'.$youtubeId.static::YOUTUBE_FOLDER_REGEX_SUFFIX)
            ->hasResults();

        if ($downloadedAlready) {
            $this->output->write(' <info>Skipped, exists already.</info>');

            return;
        }

        $filesystem->mkdir($path);

        $dl = new YoutubeDl([
            'continue' => true,
            'extract-audio' => true,
            'audio-format' => 'mp3',
            'audio-quality' => 0, // best
            'output' => '%(id)s (%(uploader)s, %(upload_date)s)/%(title)s.%(ext)s',
        ]);
        $dl->setDownloadPath($path);

        try {
            $dl->download(static::YOUTUBE_URL_PREFIX.$youtubeId);
        } catch (\Exception $e) {
            // Add more custom exceptions
            if (preg_match('/this video is unavailable/i', $e->getMessage())) {
                throw new VideoUnavailableException('The video '.$youtubeId.' is unavailable.', 0, $e);
            }
            if (preg_match('/this video has been removed by the user/i', $e->getMessage())) {
                throw new VideoRemovedByUserException('The video '.$youtubeId.' has been removed by its user.', 0, $e);
            }

            throw $e;
        }
    }

    /**
     * @param string $message
     * @param int $newLines
     */
    private function start(string $message, int $newLines = 0)
    {
        $this->output->write($message);

        for ($i = 0; $i < $newLines; $i++) {
            $this->output->write(PHP_EOL);
        }
    }

    /**
     * @param int $newLines
     */
    private function done(int $newLines = 0)
    {
        for ($i = 0; $i < $newLines; $i++) {
            $this->output->write(PHP_EOL);
        }

        $this->output->writeln(' <info>Done.</info>');
    }

    /**
     * @param \Exception $e
     * @param array &$errors
     * @param string $type
     */
    private function logError(\Exception $e, array &$errors, string $type = 'error')
    {
        $this->output->writeln(PHP_EOL.PHP_EOL.'<'.$type.'>'.$e->getMessage().'</'.$type.'>'.PHP_EOL);
        $errors[] = $e->getMessage();
    }

    /**
     * @param array $errors
     * @param string $type
     */
    private function displayErrors(array $errors, string $type = 'error')
    {
        $nbErrors = \count($errors);
        if ($nbErrors > 0) {
            $this->output->writeln(
                PHP_EOL.'<'.$type.'>There were '.$nbErrors.' errors during the download of audio files :</'.$type.'>'
            );
            (new SymfonyStyle($this->input, $this->output))->listing($errors);
        }
    }
}
