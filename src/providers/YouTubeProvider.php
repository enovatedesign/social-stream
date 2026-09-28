<?php

namespace enovate\socialstream\providers;

use Craft;
use enovate\socialstream\base\Provider;
use enovate\socialstream\models\Post;
use enovate\socialstream\providers\youtube\QuotaMeter;
use enovate\socialstream\providers\youtube\ShortsResolver;
use enovate\socialstream\providers\youtube\VideoMapper;
use enovate\socialstream\providers\youtube\WebSubSubscriber;
use enovate\socialstream\SocialStream;
use GuzzleHttp\Exception\ClientException;
use GuzzleHttp\Exception\GuzzleException;

/**
 * YouTube Data API v3 provider.
 *
 * Reading a channel's uploads takes three calls rather than Instagram's one: the
 * channel's uploads playlist ID (cached for a day — it never changes), a page of
 * that playlist, then one batched lookup of the videos on it. That shape is
 * deliberate; `search.list` would do it in one call at 100 quota units against the
 * 3 these cost.
 */
class YouTubeProvider extends Provider
{
    public const API_BASE_URL = 'https://www.googleapis.com/youtube/v3';

    public const MEDIA_TYPE_VIDEO = 'VIDEO';

    public const MEDIA_TYPE_SHORT = 'SHORT';

    /**
     * The largest page either endpoint accepts. Always request it: `playlistItems`
     * and `videos` cost one unit per call regardless of how many items come back, so
     * a smaller page buys nothing and costs the same.
     */
    private const MAX_RESULTS = 50;

    private const UPLOADS_PLAYLIST_TTL = 86400;

    private const CHANNEL_ID_TTL = 86400;

    private ?VideoMapper $mapper = null;

    private ?ShortsResolver $shorts = null;

    private ?QuotaMeter $quota = null;

    private ?WebSubSubscriber $websub = null;

    // Provider metadata
    // =========================================================================

    public static function handle(): string
    {
        return 'youtube';
    }

    public static function displayName(): string
    {
        return 'YouTube';
    }

    /**
     * `excludeNonFeed` is Instagram's flag for whether a reel reached the main feed.
     * YouTube has no equivalent, so the option is ignored here — and declaring that
     * keeps it out of this provider's cache keys.
     */
    public static function usesExcludeNonFeed(): bool
    {
        return false;
    }

    // Stream
    // =========================================================================

    /**
     * @param array $options {
     *     @type int         $siteId    Site ID (required)
     *     @type int         $limit     Number of posts to return
     *     @type string|null $mediaType Filter: VIDEO or SHORT
     *     @type string|null $after     Pagination cursor (YouTube's pageToken)
     * }
     */
    protected function doFetchStream(array $options): array
    {
        $siteId = (int) ($options['siteId'] ?? Craft::$app->sites->currentSite->id);
        $limit = max(1, (int) ($options['limit'] ?? $this->defaultLimitForSite($siteId)));
        $mediaType = $this->normaliseMediaType($options['mediaType'] ?? null);
        $after = $options['after'] ?? null;

        $token = SocialStream::$plugin->token->getAccessToken($siteId, $this->getHandle());

        if (!$token) {
            return $this->streamErrorResponse('No access token configured for this site.');
        }

        $channelId = $this->resolveChannelId($siteId, $token);

        if ($channelId === null) {
            return $this->streamErrorResponse('Could not determine the connected YouTube channel ID.');
        }

        $playlist = $this->uploadsPlaylistId($siteId, $channelId, $token);

        if ($playlist['error'] !== null) {
            return $this->streamErrorResponse($playlist['error']);
        }

        $needsFiltering = $mediaType !== null;
        $maxPages = $this->maxFetchPages();
        $pageSize = $needsFiltering ? self::MAX_RESULTS : min($limit, self::MAX_RESULTS);

        $collected = [];
        $nextCursor = $after;
        $pagesUsed = 0;

        while (count($collected) < $limit && $pagesUsed < $maxPages) {
            $page = $this->fetchPlaylistPage($playlist['id'], $token, $nextCursor, $pageSize, $siteId);

            if ($page['error'] !== null) {
                // A failure part-way through pagination fails the whole fetch. Handing
                // back the pages collected so far would be reported as a success,
                // which clears the error state just written and caches a silently
                // truncated stream.
                if ($pagesUsed > 0) {
                    SocialStream::warning(
                        'YouTube stream fetch for site ' . $siteId . ' failed after ' . $pagesUsed
                        . ' successful page(s); discarding the partial result.'
                    );
                }

                return $this->streamErrorResponse($page['error']);
            }

            $nextCursor = $page['nextCursor'];
            $pagesUsed++;

            if ($page['videoIds'] !== []) {
                $videos = $this->fetchVideos($page['videoIds'], $token, $siteId);

                if ($videos['error'] !== null) {
                    return $this->streamErrorResponse($videos['error']);
                }

                foreach ($this->buildPosts($page['videoIds'], $videos['videos']) as $post) {
                    if ($mediaType !== null && ($post->meta['mediaType'] ?? null) !== $mediaType) {
                        continue;
                    }

                    $collected[] = $post;

                    if (count($collected) >= $limit) {
                        break;
                    }
                }
            }

            if ($nextCursor === null) {
                break;
            }

            if (!$needsFiltering) {
                break;
            }
        }

        return [
            'success' => true,
            'data' => $collected,
            'nextCursor' => $nextCursor,
            'error' => null,
            'cached' => false,
        ];
    }

    /**
     * Map a batch of videos into posts, in the playlist's order.
     *
     * `videos.list` does not promise to echo the order of the IDs it was given, and
     * it silently omits anything deleted or gone private since the playlist page was
     * built — so the playlist order is the authority and missing IDs simply drop out.
     *
     * @param string[] $videoIds
     * @param array<string, array> $videos
     * @return Post[]
     */
    private function buildPosts(array $videoIds, array $videos): array
    {
        $shorts = $this->shorts()->resolve(array_keys($videos));
        $posts = [];

        foreach ($videoIds as $videoId) {
            if (!isset($videos[$videoId])) {
                continue;
            }

            $posts[] = $this->mapper()->map($videos[$videoId], $shorts[$videoId] ?? false);
        }

        return $posts;
    }

    // Profile
    // =========================================================================

    protected function doFetchProfile(int $siteId): array
    {
        $token = SocialStream::$plugin->token->getAccessToken($siteId, $this->getHandle());

        if (!$token) {
            return $this->errorResponse('No access token configured for this site.');
        }

        $channelId = $this->resolveChannelId($siteId, $token);

        $result = $this->request(
            'channels',
            $channelId === null
                ? ['part' => 'snippet,statistics', 'mine' => 'true']
                : ['part' => 'snippet,statistics', 'id' => $channelId],
            $token,
            $siteId,
        );

        if ($result['error'] !== null) {
            return $this->errorResponse($result['error']);
        }

        $channel = $result['data']['items'][0] ?? null;

        if ($channel === null) {
            return $this->errorResponse('YouTube returned no channel for this connection.');
        }

        $snippet = $channel['snippet'] ?? [];
        $statistics = $channel['statistics'] ?? [];
        $thumbnail = $this->mapper()->selectThumbnail($snippet['thumbnails'] ?? []);

        return [
            'success' => true,
            'data' => [
                'id' => $channel['id'] ?? null,
                'title' => $snippet['title'] ?? null,
                'description' => $snippet['description'] ?? null,
                'customUrl' => $snippet['customUrl'] ?? null,
                'thumbnailUrl' => $thumbnail?->url,
                // Google rounds subscriber counts publicly and hides them entirely
                // when the owner asks, hence the null rather than a zero.
                'subscriberCount' => isset($statistics['subscriberCount'])
                    ? (int) $statistics['subscriberCount']
                    : null,
                'hiddenSubscriberCount' => $statistics['hiddenSubscriberCount'] ?? false,
                'videoCount' => isset($statistics['videoCount']) ? (int) $statistics['videoCount'] : null,
                'viewCount' => isset($statistics['viewCount']) ? (int) $statistics['viewCount'] : null,
            ],
            'error' => null,
        ];
    }

    // Channel and playlist resolution
    // =========================================================================

    /**
     * Get the connected channel's ID.
     *
     * Lookup order: DB column (survives cache flushes) → cache → `channels.list`
     * with `mine=true`. Mirrors how the Instagram provider resolves its user ID, and
     * back-fills the column so later lookups skip the API.
     */
    public function resolveChannelId(int $siteId, ?string $token = null): ?string
    {
        $connection = SocialStream::$plugin->token->getConnection($siteId, $this->getHandle());

        if ($connection->providerUserId) {
            return $connection->providerUserId;
        }

        $cacheKey = 'social-stream:channel-id:' . $this->getHandle() . ':' . $siteId;
        $cached = Craft::$app->cache->get($cacheKey);

        if ($cached !== false) {
            $connection->providerUserId = $cached;
            $connection->save();

            return $cached;
        }

        $token ??= SocialStream::$plugin->token->getAccessToken($siteId, $this->getHandle());

        if (!$token) {
            return null;
        }

        $result = $this->request('channels', ['part' => 'id', 'mine' => 'true'], $token, $siteId);

        if ($result['error'] !== null) {
            return null;
        }

        $channelId = $result['data']['items'][0]['id'] ?? null;

        if ($channelId === null) {
            $this->recordError($siteId, 'The Google account has no YouTube channel.');

            return null;
        }

        Craft::$app->cache->set($cacheKey, $channelId, self::CHANNEL_ID_TTL);
        $connection->providerUserId = $channelId;
        $connection->save();

        return $channelId;
    }

    /**
     * The channel's uploads playlist ID, cached for a day — a channel's uploads
     * playlist never changes, so paying a quota unit per fetch for it is waste.
     *
     * @return array{id: string|null, error: string|null}
     */
    private function uploadsPlaylistId(int $siteId, string $channelId, string $token): array
    {
        $cacheKey = 'social-stream:youtube-uploads:' . $channelId;
        $cached = Craft::$app->cache->get($cacheKey);

        if ($cached !== false) {
            return ['id' => $cached, 'error' => null];
        }

        $result = $this->request(
            'channels',
            ['part' => 'contentDetails', 'id' => $channelId],
            $token,
            $siteId,
        );

        if ($result['error'] !== null) {
            return ['id' => null, 'error' => $result['error']];
        }

        $playlistId = $result['data']['items'][0]['contentDetails']['relatedPlaylists']['uploads'] ?? null;

        if ($playlistId === null) {
            $message = 'YouTube returned no uploads playlist for channel ' . $channelId . '.';
            $this->recordError($siteId, $message);

            return ['id' => null, 'error' => $message];
        }

        Craft::$app->cache->set($cacheKey, $playlistId, self::UPLOADS_PLAYLIST_TTL);

        return ['id' => $playlistId, 'error' => null];
    }

    // Page fetching
    // =========================================================================

    /**
     * One page of the uploads playlist, newest first.
     *
     * Only `contentDetails` is requested: the video IDs are all that is needed here,
     * and every field a template reads comes from the `videos.list` call that
     * follows.
     *
     * @return array{videoIds: string[], nextCursor: string|null, error: string|null}
     */
    private function fetchPlaylistPage(
        string $playlistId,
        string $token,
        ?string $pageToken,
        int $maxResults,
        int $siteId,
    ): array {
        $query = [
            'part' => 'contentDetails',
            'playlistId' => $playlistId,
            'maxResults' => $maxResults,
        ];

        if ($pageToken !== null && $pageToken !== '') {
            $query['pageToken'] = $pageToken;
        }

        $result = $this->request('playlistItems', $query, $token, $siteId);

        if ($result['error'] !== null) {
            return ['videoIds' => [], 'nextCursor' => null, 'error' => $result['error']];
        }

        $videoIds = [];

        foreach ($result['data']['items'] ?? [] as $item) {
            $videoId = $item['contentDetails']['videoId'] ?? null;

            if (is_string($videoId) && $videoId !== '') {
                $videoIds[] = $videoId;
            }
        }

        return [
            'videoIds' => $videoIds,
            'nextCursor' => $result['data']['nextPageToken'] ?? null,
            'error' => null,
        ];
    }

    /**
     * Full details for up to 50 video IDs in one call.
     *
     * `status` is included for `privacyStatus`, `embeddable` and `madeForKids` — it
     * adds no quota cost, and without `embeddable` a template cannot tell whether an
     * iframe will play or show an error.
     *
     * @param string[] $videoIds
     * @return array{videos: array<string, array>, error: string|null}
     */
    private function fetchVideos(array $videoIds, string $token, int $siteId): array
    {
        $result = $this->request(
            'videos',
            [
                'part' => 'snippet,contentDetails,statistics,status',
                // No maxResults: the API documents it as unsupported alongside `id`,
                // and the ID list is already capped at the batch size.
                'id' => implode(',', array_slice($videoIds, 0, self::MAX_RESULTS)),
            ],
            $token,
            $siteId,
        );

        if ($result['error'] !== null) {
            return ['videos' => [], 'error' => $result['error']];
        }

        $videos = [];

        foreach ($result['data']['items'] ?? [] as $item) {
            if (isset($item['id'])) {
                $videos[(string) $item['id']] = $item;
            }
        }

        return ['videos' => $videos, 'error' => null];
    }

    // WebSub (PubSubHubbub)
    // =========================================================================

    /**
     * Subscribe the site's callback to the channel's Atom feed, so a new upload
     * triggers a refresh instead of waiting for the cache to expire.
     *
     * Idempotent: re-subscribing resets the lease, which is exactly what renewal is.
     *
     * @return array{success: bool, error: string|null}
     */
    public function subscribeWebSub(int $siteId): array
    {
        $channelId = $this->resolveChannelId($siteId);

        if ($channelId === null) {
            return ['success' => false, 'error' => 'No connected YouTube channel to subscribe.'];
        }

        return $this->recordIfFailed($siteId, $this->websub()->subscribe($siteId, $channelId));
    }

    /**
     * Tell the hub to stop sending notifications — used when a channel is
     * disconnected, so the site stops being pinged about a feed it no longer serves.
     *
     * If another site is still watching the same channel the hub subscription stays:
     * it is shared, so cancelling it here would silently stop the other site's
     * notifications too. Only this site's lease is forgotten.
     *
     * @return array{success: bool, error: string|null}
     */
    public function unsubscribeWebSub(int $siteId): array
    {
        $channelId = $this->resolveChannelId($siteId);

        if ($channelId === null) {
            return ['success' => false, 'error' => 'No connected YouTube channel to unsubscribe.'];
        }

        if ($this->websub()->othersWatch($channelId, $siteId)) {
            $connection = SocialStream::$plugin->token->getConnection($siteId, $this->getHandle());
            $connection->websubExpiresAt = null;
            $connection->save();

            SocialStream::info(
                'Left the WebSub subscription for channel ' . $channelId
                . ' in place: another site is still watching it.'
            );

            return ['success' => true, 'error' => null];
        }

        return $this->recordIfFailed($siteId, $this->websub()->unsubscribe($siteId, $channelId));
    }

    /**
     * Where the hub delivers — shown in the CP so it can be checked against a
     * firewall or WAF when notifications aren't arriving.
     */
    public function websubCallbackUrl(): string
    {
        return $this->websub()->callbackUrl();
    }

    /**
     * The subscriber reports outcomes; the connection's error state is this class's to
     * keep, so a refusal is recorded here and nowhere else.
     *
     * @param array{success: bool, error: string|null} $result
     * @return array{success: bool, error: string|null}
     */
    private function recordIfFailed(int $siteId, array $result): array
    {
        if (!$result['success'] && $result['error'] !== null) {
            $this->recordError($siteId, $result['error']);
        }

        return $result;
    }

    // HTTP
    // =========================================================================

    /**
     * Make one Data API call, counting the quota unit it costs.
     *
     * @return array{data: array, error: string|null}
     */
    private function request(string $endpoint, array $query, string $token, int $siteId): array
    {
        try {
            $client = Craft::createGuzzleClient();
            $this->quota()->record();

            $response = $client->get(self::API_BASE_URL . '/' . $endpoint, [
                'query' => $query,
                'headers' => [
                    'Authorization' => 'Bearer ' . $token,
                    'Accept' => 'application/json',
                ],
            ]);

            $data = json_decode($response->getBody()->getContents(), true);

            if (!is_array($data)) {
                $message = 'Unexpected response from YouTube: the body was not JSON.';
                $this->recordError($siteId, $message);

                return ['data' => [], 'error' => $message];
            }

            return ['data' => $data, 'error' => null];
        } catch (ClientException $e) {
            return ['data' => [], 'error' => $this->handleApiException($e, $siteId)];
        } catch (GuzzleException $e) {
            $message = 'YouTube request failed: ' . $e->getMessage();
            $this->recordError($siteId, $message);

            return ['data' => [], 'error' => $message];
        }
    }

    /**
     * Record an API error and apply the state it implies: a cooldown until the quota
     * resets, a short cooldown for a burst limit, or the re-auth flag.
     *
     * @return string The error message, for returning to the caller.
     */
    private function handleApiException(ClientException $e, int $siteId): string
    {
        $status = $e->getResponse()->getStatusCode();
        $body = json_decode($e->getResponse()->getBody()->getContents(), true);
        $message = $body['error']['message'] ?? $e->getMessage();
        $reason = $body['error']['errors'][0]['reason'] ?? null;

        if ($status === 403 && in_array($reason, ['quotaExceeded', 'dailyLimitExceeded'], true)) {
            $seconds = $this->quota()->secondsUntilReset();
            $quotaMessage = 'YouTube Data API quota exhausted for today. Calls resume when it resets at '
                . 'midnight Pacific Time (about ' . max(1, (int) round($seconds / 3600)) . ' hour(s)).';

            // A daily quota does not recover in fifteen minutes, so the default
            // cooldown would just retry into the same wall all day.
            $this->enterRateLimitCooldown($siteId, $seconds);
            $this->recordError($siteId, $quotaMessage);

            return $quotaMessage;
        }

        if ($status === 429 || ($status === 403 && in_array($reason, ['rateLimitExceeded', 'userRateLimitExceeded'], true))) {
            $rateMessage = 'Rate limited by YouTube: ' . $message;
            $this->enterRateLimitCooldown($siteId);
            $this->recordError($siteId, $rateMessage);

            return $rateMessage;
        }

        $this->recordError($siteId, $message);

        // 401 is Google's answer for a credential it will not accept. The inline
        // refresh should have prevented a merely expired access token reaching this
        // point, so what is left needs a human to reconnect.
        if ($status === 401) {
            $this->markNeedsReauth($siteId);
        }

        return $message;
    }

    // Helpers
    // =========================================================================

    /**
     * Normalise the `mediaType` filter. Anything other than VIDEO or SHORT is
     * ignored with a warning rather than silently filtering the feed to nothing.
     */
    private function normaliseMediaType(mixed $mediaType): ?string
    {
        if ($mediaType === null || $mediaType === '') {
            return null;
        }

        $normalised = strtoupper((string) $mediaType);

        if (in_array($normalised, [self::MEDIA_TYPE_VIDEO, self::MEDIA_TYPE_SHORT], true)) {
            return $normalised;
        }

        SocialStream::warning(
            "Ignoring unsupported YouTube mediaType '{$mediaType}'. Use 'VIDEO' or 'SHORT'."
        );

        return null;
    }

    private function mapper(): VideoMapper
    {
        return $this->mapper ??= new VideoMapper();
    }

    private function shorts(): ShortsResolver
    {
        return $this->shorts ??= new ShortsResolver();
    }

    public function quota(): QuotaMeter
    {
        return $this->quota ??= new QuotaMeter();
    }

    private function websub(): WebSubSubscriber
    {
        return $this->websub ??= new WebSubSubscriber();
    }
}
