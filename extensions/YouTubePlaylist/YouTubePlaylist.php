<?php declare(strict_types=1);

namespace Extension\YouTubePlaylist;

use App\Collection\Collection;
use App\Domain\Content;
use App\Domain\Contents;
use App\Domain\ContentsProcessor;
use App\Domain\Path;
use App\Domain\PathPart;
use App\Domain\ProjectRootPathAware;
use App\UI\Skippable;
use App\UI\UserInterface;
use Google_Client;
use Google_Service_YouTube;
use Google_Service_YouTube_PlaylistItem;
use Google_Service_YouTube_PlaylistItemSnippet;
use Google_Service_YouTube_ResourceId;

final class YouTubePlaylist extends ContentsProcessor implements ProjectRootPathAware
{
    use Skippable;

    private const YOUTUBE_PLAYLIST_URL = 'https://www.youtube.com/playlist?list=%s';

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

        foreach (['playlist_id', 'auth_config_path', 'youtube_url_extractor'] as $configKey) {
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

        $updates = [
            'remove' => [],
            'add' => [],
        ];
        $allPlaylistItems = $this->getAllPlaylistItems('snippet', $this->config['playlist_id']);

        /** @var \App\Collection\Collection|string[] $videosIdsInPlaylist */
        $videosIdsInPlaylist = $allPlaylistItems
            ->map(
                function (Google_Service_YouTube_PlaylistItem $playlistItem) {
                    return $playlistItem->getSnippet()->getResourceId()->getVideoId();
                }
            );

        /** @var \App\Collection\Collection|string[] $videosIdsInContents */
        $videosIdsInContents = $contents
            ->map(
                function (Content $content) {
                    return $this->getYouTubeVideoIdFromContent($content);
                }
            )
            ->filter(
                function (string $youtubeId) {
                    return !empty($youtubeId);
                }
            );

        $updates['remove'] = $allPlaylistItems
            ->filter(
                function (Google_Service_YouTube_PlaylistItem $playlistItem) use ($videosIdsInContents) {
                    return !$videosIdsInContents->contains($playlistItem->getSnippet()->getResourceId()->getVideoId());
                }
            )
            ->map(
                function (Google_Service_YouTube_PlaylistItem $playlistItem) {
                    return $playlistItem->getId();
                }
            );

        $updates['add'] = $videosIdsInContents
            ->filter(
                function (string $youtubeVideoId) use ($videosIdsInPlaylist) {
                    return !$videosIdsInPlaylist->contains($youtubeVideoId);
                }
            );

        foreach ($updates as $action => $ids) {
            if (!$this->shouldUpdatePlaylist($ids, $action)) {
                continue;
            }

            foreach ($ids as $id) {
                try {
                    $this->ui->write($this->ui->indent(2).'* [<comment>'.$id.'</comment>] ... ');
                    $this->updatePlaylist($action, $id, $this->config['playlist_id']);
                    $this->ui->write('<info>Done.</info>');
                } catch (\Exception $e) {
                    $json = json_decode($e->getMessage(), true);
                    foreach ($json['error']['errors'] as $error) {
                        $this->ui->write('<error>'.$error['message'].'</error> ');
                    }
                } finally {
                    $this->ui->write(PHP_EOL);
                }
            }

            $this->ui->write(PHP_EOL);
        }
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

    /**
     * @param string $parts
     * @param string $playlistId
     *
     * @return \Extension\YouTubePlaylist\PlaylistItems
     * @throws \Google_Exception
     */
    private function getAllPlaylistItems(string $parts, string $playlistId): PlaylistItems
    {
        $playlistItems = new PlaylistItems();
        $params = [
            'maxResults' => 50,
            'playlistId' => $playlistId,
        ];

        do {
            $results = $this->getYouTubeService()->playlistItems->listPlaylistItems($parts, $params);

            /** @var Google_Service_YouTube_PlaylistItem $playlistItem */
            foreach ($results->getItems() as $playlistItem) {
                $playlistItems->add($playlistItem);
            }

            if (null !== ($nextPageToken = $results->getNextPageToken())) {
                $params['pageToken'] = $nextPageToken;
            }
        } while ($nextPageToken !== null);

        return $playlistItems;
    }

    /**
     * @param string $action
     * @param string $videoId
     * @param string $playlistId
     *
     * @throws \UnexpectedValueException
     */
    private function updatePlaylist(string $action, string $videoId, string $playlistId): void
    {
        $method = $action.'VideoInPlaylist';
        if (!method_exists($this, $method)) {
            throw new \UnexpectedValueException(
                sprintf('The method "%s" does not exist on object "%s".', $method, self::class)
            );
        }
        $this->{$method}($videoId, $playlistId);
    }

    /**
     * @param string $videoId
     * @param string $playlistId
     *
     * @throws \Google_Exception
     */
    /** @noinspection PhpUnusedPrivateMethodInspection */
    private function addVideoInPlaylist(string $videoId, string $playlistId): void
    {
        // First, define the resource being added to the playlist by setting its video ID and kind.
        $resourceId = new Google_Service_YouTube_ResourceId();
        $resourceId->setVideoId($videoId);
        $resourceId->setKind('youtube#video');

        // Then, define a snippet for the playlist item. Add the resource ID and the playlist ID to the snippet.
        $playlistItemSnippet = new Google_Service_YouTube_PlaylistItemSnippet();
        $playlistItemSnippet->setPlaylistId($playlistId);
        $playlistItemSnippet->setResourceId($resourceId);

        // Finally, define a playlist item
        $playlistItem = new Google_Service_YouTube_PlaylistItem();
        $playlistItem->setSnippet($playlistItemSnippet);

        // And insert it
        $this->getYouTubeService()->playlistItems->insert('snippet,contentDetails', $playlistItem);
    }

    /**
     * @param string $videoId
     *
     * @throws \Google_Exception
     */
    /** @noinspection PhpUnusedPrivateMethodInspection */
    private function removeVideoInPlaylist(string $videoId): void
    {
        $this->getYouTubeService()->playlistItems->delete($videoId);
    }

    /**
     * @param Content $content
     *
     * @return string
     */
    private function getYouTubeVideoIdFromContent(Content $content): string
    {
        if (!preg_match($this->config['youtube_url_extractor']['regex'], $content->getData(), $matches)) {
            return '';
        }

        return $matches[$this->config['youtube_url_extractor']['video_id_index']];
    }

    /**
     * @param \App\Collection\Collection $videoIds
     * @param string $action
     *
     * @return bool
     */
    private function shouldUpdatePlaylist(Collection $videoIds, string $action): bool
    {
        $this->ui->writeln(
            sprintf(
                '%s videos on the playlist <info>%s</info>...'.PHP_EOL,
                ucfirst($action),
                sprintf(static::YOUTUBE_PLAYLIST_URL, $this->config['playlist_id'])
            )
        );

        return $this->shouldProcess(
            $this->ui,
            $videoIds,
            sprintf(
                '%sThe script is about to %s <question> %s </question> videos on the playlist. '.PHP_EOL,
                $this->ui->indent(),
                $action,
                $videoIds->count()
            ),
            'add'
        );
    }
}
