<?php declare(strict_types=1);

namespace App;

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
    const DOWNLOAD_PATH = __DIR__.DIRECTORY_SEPARATOR.'..'.DIRECTORY_SEPARATOR.'data'.DIRECTORY_SEPARATOR;

    const TRELLO_API_KEY = 'beb1cf49bfceca0ea4f7dc063fe54f37'; // fetched from https://trello.com/app-key
    const TRELLO_REPERTOIRE_BOARD_ID = 'MkYHGxzY'; // full URL is https://trello.com/b/MkYHGxzY/repertoire
    const TRELLO_IMPROVISATIONS_LIST_ID = '588b79626416a37b42587e3b';

    // See https://stackoverflow.com/a/37704433/389519
    const YOUTUBE_URL_REGEX = '#\b((?:https?:)?\/\/)?((?:www|m)\.)?((?:youtube\.com|youtu.be))(\/(?:[\w\-]+\?v=|embed\/|v\/)?)([\w\-]+)(\S+)?#i';
    const YOUTUBE_URL_PREFIX = 'https://www.youtube.com/watch?v=';

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
     */
    public function execute(InputInterface $input, OutputInterface $output)
    {
        $this->start('Fetch the boards\' lists and cards information from Trello...');
        list($cards, $lists) = $this->getTrelloInformation();
        $this->done();

        $this->start('Check for YouTube URLs in the cards...');
        $songs = $this->getSongs($cards, $lists);
        $this->done();

        $this->start('Clean the download folder...');
        $this->cleanDownloadFolder($songs);
        $this->done();

        $this->start('Download songs from YouTube...', true);
        $this->downloadSongsFromYouTube($songs);
        $this->done(true);

        $this->output->write(PHP_EOL);
    }

    /**
     * @return array
     */
    private function getTrelloInformation(): array
    {
        /** @var \App\PhpDoc\TrelloClient $trello */
        $trello = new Client(['token' => static::TRELLO_API_KEY]);
        $trelloLists = $trello->getBoardLists(static::TRELLO_REPERTOIRE_BOARD_ID);
        $trelloCards = $trello->getBoardCards(static::TRELLO_REPERTOIRE_BOARD_ID);

        // Filter out the improvisations cards
        $cards = array_filter(
            $trelloCards,
            function (/** @var \App\PhpDoc\TrelloCard $card */$card) {
                return $card->idList !== static::TRELLO_IMPROVISATIONS_LIST_ID;
            }
        );

        return [
            array_combine(array_column($cards, 'id'), $cards),
            array_combine(array_column($trelloLists, 'id'), $trelloLists)
        ];
    }

    /**
     * @param \App\PhpDoc\TrelloCard[] $cards
     * @param \App\PhpDoc\TrelloList[] $lists
     *
     * @return \App\Song[]
     */
    private function getSongs(array $cards, array $lists): array
    {
        $songs = [];
        foreach ($cards as $card) {
            preg_match_all(static::YOUTUBE_URL_REGEX, $card->desc, $youtubeUrls);
            $youtubeIds = $youtubeUrls[5];

            if (empty($youtubeIds)) {
                continue;
            }

            $songs[] = new Song($youtubeIds, $lists[$card->idList]->name, $card->name);
        }

        return $songs;
    }

    /**
     * @param \App\Song[] $songs
     */
    private function cleanDownloadFolder(array $songs)
    {
        /** @todo Implement... */
        $this->output->write(' <comment>Skipped... not implemented yet.</comment>');
    }

    /**
     * @param \App\Song[] $songs
     */
    private function downloadSongsFromYouTube(array $songs)
    {
        $errors = [];

        foreach ($songs as $song) {
            $this->start('Download the audio(s) for "'.$song->getName().'"...', true);

            $progressBar = new ProgressBar($this->output, \count($song->getYoutubeIds()));
            $progressBar->setMessage('Download the audio(s) for "'.$song->getName().'"...');
            $progressBar->start();

            foreach ($song->getYoutubeIds() as $youtubeId) {
                try {
                    $this->downloadSongFromYouTube(static::DOWNLOAD_PATH.$song->getPath(), $youtubeId);
                } catch (\Exception $e) {
                    $errors[] = $e->getMessage();
                }

                $progressBar->advance();
            }
            $progressBar->finish();
            $this->done();
        }

        $nbErrors = \count($errors);
        if ($nbErrors > 0) {
            $this->output->writeln(
                sprintf(PHP_EOL.'<error>There were %s errors during the download of audio files :</error>', $nbErrors)
            );
            (new SymfonyStyle($this->input, $this->output))->listing($errors);
        }
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
            ->name($youtubeId.' *')
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
        $dl->download(static::YOUTUBE_URL_PREFIX.$youtubeId);
    }

    /**
     * @param string $message
     * @param bool $newLine
     */
    private function start($message, $newLine = false)
    {
        $this->output->write($message);

        if ($newLine) {
            $this->output->write(PHP_EOL);
        }
    }

    /**
     * @param bool $newLine
     */
    private function done($newLine = false)
    {
        if ($newLine) {
            $this->output->write(PHP_EOL);
        }

        $this->output->writeln(' <info>Done.</info>');
    }
}
