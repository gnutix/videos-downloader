<?php declare(strict_types=1);

namespace Extension\YouTubePlaylist;

use App\Domain\Content;
use App\Domain\Contents;
use App\Domain\ContentsProcessor;
use App\Domain\Path;
use App\Domain\PathPart;
use App\Domain\ProjectRootPathAware;
use Google_Client;
use Google_Service_YouTube;
use Google_Service_YouTube_PlaylistItem;
use Google_Service_YouTube_PlaylistItemSnippet;
use Google_Service_YouTube_ResourceId;

final class YouTubePlaylist extends ContentsProcessor implements ProjectRootPathAware
{
    private const YOUTUBE_PLAYLIST_URL = 'https://www.youtube.com/playlist?list=%s';

    /** @var Path */
    private $projectRootPath;

    /** @var Google_Service_YouTube */
    private $youtubeService;

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
        foreach (['playlist_id', 'auth_config_path', 'youtube_url_extractor'] as $configKey) {
            if (empty($this->config[$configKey])) {
                throw new \RuntimeException(sprintf('The "%s" configuration key is mandatory.', $configKey));
            }
        }

        $this->syncYouTubePlaylistWithContents($contents);
    }

    /**
     * @param Contents $contents
     *
     * @throws \Google_Exception
     */
    private function syncYouTubePlaylistWithContents(Contents $contents): void
    {
        $this->ui->writeln(
            sprintf(
                PHP_EOL.'Append the following videos to the playlist <info>%s</info>...'.PHP_EOL,
                sprintf(static::YOUTUBE_PLAYLIST_URL, $this->config['playlist_id'])
            )
        );

        $videosIdsAlreadyInPlaylist = $this->getAllPlaylistItems('snippet', $this->config['playlist_id'])
            ->map(
                function (Google_Service_YouTube_PlaylistItem $playlistItem) {
                    return $playlistItem->getSnippet()->getResourceId()->getVideoId();
                }
            );

        /** @var string[] $videosIdsToInsert */
        $videosIdsToInsert = $contents
            ->map(
                function (Content $content) {
                    return $this->getYouTubeVideoIdFromContent($content);
                }
            )
            ->filter(
                function ($youtubeVideoId) use ($videosIdsAlreadyInPlaylist) {
                    return !empty($youtubeVideoId) && !$videosIdsAlreadyInPlaylist->contains($youtubeVideoId);
                }
            );

        foreach ($videosIdsToInsert as $youtubeVideoId) {
            try {
                $this->ui->write(' * '.$youtubeVideoId.' ... ');
                $this->insertVideoInPlaylist($youtubeVideoId, $this->config['playlist_id']);
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
            $authCode = $this->ui->askQuestion('Then, enter the verification code here: ');

            // Exchange authorization code for an access token.
            $accessToken = $client->fetchAccessTokenWithAuthCode($authCode);

            // Store the credentials to disk.
            file_put_contents($credentialsPath, json_encode($accessToken));
            $this->ui->writeln(sprintf('Credentials saved to "<info>%s</info>"', $credentialsPath));
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
     * @param string $videoId
     * @param string $playlistId
     *
     * @throws \Google_Exception
     */
    private function insertVideoInPlaylist(string $videoId, string $playlistId): void
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
}
