<?php declare(strict_types=1);

namespace App;

use League\Flysystem\Adapter\Local;
use League\Flysystem\Filesystem;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Helper\ProgressBar;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Stevenmaguire\Services\Trello\Client;
use Symfony\Component\Console\Style\SymfonyStyle;
use YoutubeDl\YoutubeDl;

final class RepertoireDownloader extends Command
{
    const FILESYSTEM_LOCAL_PATH = __DIR__.'/../../data';

    const TRELLO_API_KEY = 'beb1cf49bfceca0ea4f7dc063fe54f37'; // fetched from https://trello.com/app-key
    const TRELLO_REPERTOIRE_BOARD_ID = 'MkYHGxzY'; // full URL is https://trello.com/b/MkYHGxzY/repertoire
    const TRELLO_IMPROVISATIONS_LIST_ID = '588b79626416a37b42587e3b';

    // See https://stackoverflow.com/a/37704433/389519
    const YOUTUBE_URL_PREFIX = 'https://www.youtube.com/watch?v=';
    const YOUTUBE_URL_REGEX = '#\b((?:https?:)?\/\/)?((?:www|m)\.)?((?:youtube\.com|youtu.be))(\/(?:[\w\-]+\?v=|embed\/|v\/)?)([\w\-]+)(\S+)?#i';

    /** @var InputInterface */
    private $input;

    /** @var OutputInterface */
    private $output;

    /** @var \App\PhpDoc\TrelloClient */
    private $trello;

    /** @var \App\PhpDoc\Filesystem */
    private $filesystem;

    /** @var array */
    private $errors;

    /**
     * {@inheritdoc}
     * @throws \LogicException
     */
    public function initialize(InputInterface $input, OutputInterface $output)
    {
        $this->input = $input;
        $this->output = $output;

        $this->trello = new Client(['token' => static::TRELLO_API_KEY]);
        $this->filesystem = new Filesystem(new Local(static::FILESYSTEM_LOCAL_PATH));
    }

    /**
     * {@inheritdoc}
     */
    public function execute(InputInterface $input, OutputInterface $output)
    {
        $this->start('Fetch the boards\' lists and cards information from Trello...');
        list($cards, $lists) = $this->getTrelloInformation();
        $this->done();

        $this->start('Check the cards for YouTube links...');
        $songs = $this->getSongs($cards, $lists);
        $this->done();

        $this->start('Clean the download folder...');
        $this->cleanDownloadFolder($songs);
        $this->done();

        $this->start('Download audio files from YouTube...');
        $this->downloadSongs($songs);
        $this->done();

        $this->output->write(PHP_EOL);
    }

    /**
     * @return array
     */
    private function getTrelloInformation(): array
    {
        $trelloLists = $this->trello->getBoardLists(static::TRELLO_REPERTOIRE_BOARD_ID);
        $trelloCards = $this->trello->getBoardCards(static::TRELLO_REPERTOIRE_BOARD_ID);

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
    private function downloadSongs(array $songs)
    {
        foreach ($songs as $song) {
            $this->start('Download the audio(s) for "'.$song->getName().'"...', true);

            $progressBar = new ProgressBar($this->output, \count($song->getYoutubeIds()));
            $progressBar->setMessage('Download the audio(s) for "'.$song->getName().'"...');
            $progressBar->start();

            foreach ($song->getYoutubeIds() as $youtubeId) {
                $this->downloadAudioFromYouTube(
                    $song->getPath().DIRECTORY_SEPARATOR.$youtubeId,
                    static::YOUTUBE_URL_PREFIX.$youtubeId
                );

                $progressBar->advance();
            }
            $progressBar->finish();
            $this->done();
        }

        if (\count($this->errors) > 0) {
            $io = new SymfonyStyle($this->input, $this->output);
            $this->output->writeln(PHP_EOL.'<error>There were errors during the download process :</error>');
            $io->listing($this->errors);
        }
    }

    /**
     * @param string $path
     * @param string $url
     */
    private function downloadAudioFromYouTube($path, $url)
    {
        // If the file exists, we stop here
        if ($this->filesystem->has($path)) {
            return;
        }

        $this->filesystem->createDir($path);

        $dl = new YoutubeDl([
            'continue' => true,
            'extract-audio' => true,
            'audio-format' => 'mp3',
            'audio-quality' => 0, // best
            'output' => '%(uploader)s - %(title)s.%(ext)s',
        ]);
        $dl->setDownloadPath($this->filesystem->getAdapter()->getPathPrefix() . $path); // here it needs the absolute path

        try {
            $dl->download($url);
        } catch (\Exception $e) {
            $this->errors[] = $e;
        }
    }

    /**
     * @param string $message
     * @param bool $newLine
     */
    private function start($message, $newLine = false)
    {
        $this->output->write('<comment>'.$message.'</comment>');

        if ($newLine) {
            $this->output->write(PHP_EOL);
        }
    }

    private function done()
    {
        $this->output->writeln(' <info>Done.</info>');
    }
}
