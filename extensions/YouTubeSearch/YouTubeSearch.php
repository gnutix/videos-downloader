<?php declare(strict_types=1);

namespace Extension\YouTubeSearch;

use App\Domain\Content;
use App\Domain\Contents;
use App\Domain\ContentsProcessor;
use App\Domain\Path;
use App\Domain\PathPart;
use App\Domain\ProjectRootPathAware;
use App\UI\UserInterface;
use Google_Client;
use Google_Service_YouTube;

final class YouTubeSearch extends ContentsProcessor implements ProjectRootPathAware
{
    private const YOUTUBE_VIDEO_URL = 'https://www.youtube.com/watch?v=%s';

    /** @var Path */
    private $projectRootPath;

    /** @var Google_Service_YouTube */
    private $youtubeService;

    /**
     * {@inheritdoc}
     */
    public function __construct(UserInterface $ui, array $config = [])
    {
        parent::__construct($ui, $config);

        foreach (['auth_config_path'] as $configKey) {
            if (empty($this->config[$configKey])) {
                throw new \RuntimeException(sprintf('The "%s" configuration key is mandatory.', $configKey));
            }
        }

    }

    /**
     * {@inheritdoc}
     */
    public function setProjectRootPath(Path $projectRootPath): void
    {
        $this->projectRootPath = $projectRootPath;
    }

    /**
     * {@inheritdoc}
     */
    protected function getConfigFilePath(): string
    {
        return __DIR__.DIRECTORY_SEPARATOR.'config/config.yml';
    }

    /**
     * {@inheritdoc}
     */
    public function processContents(Contents $contents): void
    {
        if ($contents->isEmpty()) {
            return;
        }

        $this->ui->writeln('Looking for original YouTube videos : '.PHP_EOL);

        $displayTable = function (&$rows, $lastListName) {
            $this->ui->getSymfonyStyle()->section($lastListName);
            $this->ui->getSymfonyStyle()->table(
                ['Card name', 'YouTube URL'],
                $rows
            );
            $rows = [];
        };

        $rows = [];
        $lastListName = null;
        foreach ($contents as $content) {
            $cardName = $content->getData()['[name]'];
            $listName = $content->getData()['_trello_list_name'];

            if (null === $lastListName) {
                $lastListName = $listName;
            } elseif ($listName !== $lastListName) {
                $displayTable($rows, $lastListName);
                $lastListName = $listName;
            }

            $youtubeSearchResults = $this->getYouTubeService()->search->listSearch(
                'snippet',
                [
                    'type' => 'video',
                    'maxResults' => 1,
                    'q' => $cardName,
                ]
            );

            /** @var \Google_Service_YouTube_SearchResult $youtubeSearchResult */
            foreach ($youtubeSearchResults as $youtubeSearchResult) {
                $youtubeVideoUrl = sprintf(
                    static::YOUTUBE_VIDEO_URL,
                    $youtubeSearchResult->getId()->getVideoId()
                );
                $rows[] = [$cardName, $youtubeVideoUrl];

                /**
                 * @todo Add OAuth to Trello Client and do that directly :
                 * @see https://github.com/stevenmaguire/trello-php#authenticate-your-users-and-store-access-token
                 */
                /*
                $content->getData()['_trello_client']->updateCardCustomField(
                    $content->getData()['[id]'],
                    $this->config['trello_custom_field_id'],
                    ['value' => ['text' => $youtubeVideoUrl]]
                );
                */
            }
        }

        $displayTable($rows, $lastListName);
    }

    /**
     * @return Google_Service_YouTube
     * @throws \Google_Exception
     */
    private function getYouTubeService(): Google_Service_YouTube
    {
        if (null === $this->youtubeService) {
            $this->youtubeService = new Google_Service_YouTube($this->createGoogleClient());
        }

        return $this->youtubeService;
    }

    /**
     * @return Google_Client
     * @throws \Google_Exception
     */
    private function createGoogleClient(): Google_Client
    {
        $client = new Google_Client();
        $client->addScope(Google_Service_YouTube::YOUTUBE);
        $client->setAccessType('offline');
        $client->setAuthConfig((string) $this->getAuthConfigFilePath());

        return $this->authenticateGoogleClient($client);
    }

    /**
     * @return Path
     */
    private function getAuthConfigFilePath(): Path
    {
        return new Path([
            new PathPart([
                'path' => $this->config['auth_config_path'],
                'substitutions' => [
                    '%project_root%' => $this->projectRootPath
                ]
            ])
        ]);
    }

    /**
     * @param Google_Client $client
     *
     * @return Google_Client
     */
    private function authenticateGoogleClient(Google_Client $client): Google_Client
    {
        // Load previously authorized credentials from a file.
        $credentialsPath = (string) $this->projectRootPath.'/var/youtube_playlist_extension_credentials.json';

        if (file_exists($credentialsPath)) {
            $accessToken = json_decode(file_get_contents($credentialsPath), true);
        } else {
            // Request authorization from the user.
            $authUrl = $client->createAuthUrl();
            $this->ui->writeln(
                sprintf(
                    '<error>Open the following link in your browser:</error>'.PHP_EOL.PHP_EOL.'<info>%s</info>'.PHP_EOL,
                    $authUrl
                )
            );

            // As we can't have a default value for this question, we need to force interactive mode
            $interactive = $this->ui->isInteractive();
            if (!$interactive) {
                $this->ui->setInteractive(true);
            }

            $authCode = $this->ui->forceInteractive(function () {
                return $this->ui->askQuestion('Then, enter the verification code here: ');
            });

            // Exchange authorization code for an access token.
            $accessToken = $client->fetchAccessTokenWithAuthCode($authCode);

            // Store the credentials to disk.
            file_put_contents($credentialsPath, json_encode($accessToken));
            $this->ui->writeln(
                sprintf(
                    'Credentials saved to <info>%s</info>.'.PHP_EOL,
                    str_replace((string) $this->projectRootPath.DIRECTORY_SEPARATOR, '', $credentialsPath)
                )
            );
        }
        $client->setAccessToken($accessToken);

        // Refresh the token if it's expired.
        if ($client->isAccessTokenExpired()) {
            $client->fetchAccessTokenWithRefreshToken($client->getRefreshToken());
            file_put_contents($credentialsPath, json_encode($client->getAccessToken()));
        }

        return $client;
    }
}
