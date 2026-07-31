<?php

namespace enovate\socialstream\providers;

use Craft;
use craft\helpers\DateTimeHelper;
use enovate\socialstream\base\Provider;
use enovate\socialstream\models\Post;
use enovate\socialstream\models\PostAuthor;
use enovate\socialstream\models\PostMedia;
use enovate\socialstream\records\SettingsRecord;
use enovate\socialstream\SocialStream;
use GuzzleHttp\Exception\ClientException;
use GuzzleHttp\Exception\GuzzleException;

/**
 * Instagram Graph API provider.
 */
class InstagramProvider extends Provider
{
    public const API_VERSION = 'v21.0';

    public const API_BASE_URL = 'https://graph.instagram.com';

    public const TOKEN_BASE_URL = 'https://graph.instagram.com';

    /**
     * Meta's error code for a token it will not accept — expired, revoked, or
     * invalidated. Only re-authorisation clears it.
     */
    public const ERROR_CODE_INVALID_TOKEN = 190;

    /**
     * Default maximum number of API pages to fetch when filtering reduces results.
     * Can be overridden via config/social-stream.php: 'maxFetchPages' => 5
     */
    private const DEFAULT_MAX_FETCH_PAGES = 3;

    /**
     * Default number of media items to request per API page when filtering is
     * active. Deliberately independent of the caller's limit — see
     * {@see doFetchStream()}. Can be overridden via config/social-stream.php:
     * 'fetchPageSize' => 50
     */
    private const DEFAULT_FETCH_PAGE_SIZE = 25;

    /**
     * The largest page size Instagram will accept on the media edge.
     */
    private const MAX_FETCH_PAGE_SIZE = 100;

    private const STREAM_FIELDS = 'id,caption,media_type,media_url,thumbnail_url,permalink,timestamp,like_count,comments_count,is_shared_to_feed,media_product_type,shortcode,owner';

    private const CHILDREN_FIELDS = 'id,media_type,media_url,thumbnail_url,permalink,timestamp';

    private const PROFILE_FIELDS = 'id,user_id,username,name,account_type,profile_picture_url,followers_count,follows_count,media_count';

    // Provider metadata
    // =========================================================================

    public static function handle(): string
    {
        return 'instagram';
    }

    public static function displayName(): string
    {
        return 'Instagram';
    }

    // Stream
    // =========================================================================

    /**
     * @param array $options {
     *     @type int      $siteId         Site ID (required)
     *     @type int      $limit          Number of posts to return
     *     @type string   $mediaType      Filter: IMAGE, VIDEO, CAROUSEL_ALBUM
     *     @type bool     $excludeNonFeed Exclude posts where is_shared_to_feed is false
     *     @type string   $after          Pagination cursor
     * }
     */
    protected function doFetchStream(array $options): array
    {
        $siteId = (int) ($options['siteId'] ?? Craft::$app->sites->currentSite->id);
        $limit = $options['limit'] ?? $this->defaultLimitForSite($siteId);
        $mediaType = $options['mediaType'] ?? null;
        $excludeNonFeed = $options['excludeNonFeed'] ?? $this->excludeNonFeedForSite($siteId);
        $after = $options['after'] ?? null;

        $token = SocialStream::$plugin->token->getAccessToken($siteId, $this->getHandle());

        if (!$token) {
            return $this->streamErrorResponse('No access token configured for this site.');
        }

        $userId = $this->resolveUserId($siteId, $token);

        if ($userId === null) {
            return $this->streamErrorResponse('Could not determine Instagram user ID.');
        }

        $needsFiltering = $mediaType !== null || $excludeNonFeed;
        $maxPages = $this->maxFetchPages();

        $pageSize = $this->resolvePageSize($limit, $needsFiltering, $this->configuredPageSize());

        $collected = [];
        $nextCursor = $after;
        $pagesUsed = 0;

        while (count($collected) < $limit && $pagesUsed < $maxPages) {
            $page = $this->fetchMediaPage($userId, $token, $pageSize, $nextCursor, $siteId);

            if ($page['error'] !== null) {
                // A failure at any point fails the whole fetch, including one that
                // interrupts pagination part-way. Returning the pages collected so
                // far would be reported as a success, which would then clear the
                // lastError and needsReauthAt just written and cache a silently
                // truncated stream — the failure mode this release exists to close.
                if ($pagesUsed > 0) {
                    SocialStream::warning(
                        'Stream fetch for site ' . $siteId . ' failed after ' . $pagesUsed
                        . ' successful page(s); discarding the partial result.'
                    );
                }

                return $this->streamErrorResponse($page['error']);
            }

            $posts = $page['posts'];
            $nextCursor = $page['nextCursor'];
            $pagesUsed++;

            foreach ($posts as $post) {
                if ($mediaType !== null && $this->rawMediaType($post) !== strtoupper($mediaType)) {
                    continue;
                }

                if ($excludeNonFeed && ($post->meta['isSharedToFeed'] ?? true) === false) {
                    continue;
                }

                $collected[] = $post;

                if (count($collected) >= $limit) {
                    break;
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

    protected function doFetchProfile(int $siteId): array
    {
        $token = SocialStream::$plugin->token->getAccessToken($siteId, $this->getHandle());

        if (!$token) {
            return $this->errorResponse('No access token configured for this site.');
        }

        try {
            $client = Craft::createGuzzleClient();
            $response = $client->get($this->apiUrl('me'), [
                'query' => [
                    'fields' => self::PROFILE_FIELDS,
                    'access_token' => $token,
                ],
            ]);

            $data = json_decode($response->getBody()->getContents(), true);

            return [
                'success' => true,
                'data' => $data,
                'error' => null,
            ];
        } catch (ClientException $e) {
            // handleApiException() already consumed the response body, so take the
            // message from its return value rather than re-reading the stream.
            return $this->errorResponse($this->handleApiException($e, $siteId));
        } catch (GuzzleException $e) {
            $this->recordError($siteId, $e->getMessage());
            return $this->errorResponse('Failed to fetch profile: ' . $e->getMessage());
        }
    }

    // Page fetching
    // =========================================================================

    /**
     * Fetch a single page of media.
     *
     * A failed request is reported via the 'error' key rather than a null return,
     * so callers can tell "the API said no" apart from "there are no more pages".
     *
     * @param int $pageSize How many items to request — the API page size, not the
     *                      caller's limit. {@see doFetchStream()} for why they differ.
     * @return array{posts: Post[], nextCursor: string|null, error: string|null}
     */
    private function fetchMediaPage(string $userId, string $token, int $pageSize, ?string $after, int $siteId): array
    {
        try {
            $client = Craft::createGuzzleClient();
            $query = [
                'fields' => self::STREAM_FIELDS,
                'limit' => $pageSize,
                'access_token' => $token,
            ];

            if ($after !== null) {
                $query['after'] = $after;
            }

            $response = $client->get($this->apiUrl($userId . '/media'), [
                'query' => $query,
            ]);

            $data = json_decode($response->getBody()->getContents(), true);

            if (!isset($data['data'])) {
                $message = 'Unexpected response from Instagram: no data property.';
                $this->recordError($siteId, $message);
                return ['posts' => [], 'nextCursor' => null, 'error' => $message];
            }

            $posts = [];
            foreach ($data['data'] as $item) {
                $post = $this->mapToPost($item);

                if (($item['media_type'] ?? null) === 'CAROUSEL_ALBUM') {
                    $post->children = $this->fetchCarouselChildren($post->id, $token, $siteId);
                }

                $posts[] = $post;
            }

            $nextCursor = $data['paging']['cursors']['after'] ?? null;
            if (!isset($data['paging']['next'])) {
                $nextCursor = null;
            }

            return [
                'posts' => $posts,
                'nextCursor' => $nextCursor,
                'error' => null,
            ];
        } catch (ClientException $e) {
            return [
                'posts' => [],
                'nextCursor' => null,
                'error' => $this->handleApiException($e, $siteId),
            ];
        } catch (GuzzleException $e) {
            $message = 'Stream fetch failed: ' . $e->getMessage();
            $this->recordError($siteId, $message);
            return ['posts' => [], 'nextCursor' => null, 'error' => $message];
        }
    }

    /**
     * @return Post[]
     */
    private function fetchCarouselChildren(string $mediaId, string $token, int $siteId): array
    {
        try {
            $client = Craft::createGuzzleClient();
            $response = $client->get($this->apiUrl($mediaId . '/children'), [
                'query' => [
                    'fields' => self::CHILDREN_FIELDS,
                    'access_token' => $token,
                ],
            ]);

            $data = json_decode($response->getBody()->getContents(), true);

            if (!isset($data['data'])) {
                return [];
            }

            return array_map(fn(array $child) => $this->mapToPost($child), $data['data']);
        } catch (GuzzleException $e) {
            SocialStream::warning('Failed to fetch carousel children for ' . $mediaId . ': ' . $e->getMessage());
            return [];
        }
    }

    // Mapping
    // =========================================================================

    /**
     * Map a raw Instagram Graph API media object into a generic Post.
     */
    private function mapToPost(array $item): Post
    {
        $post = new Post();
        $post->id = $item['id'] ?? null;
        $post->provider = $this->getHandle();
        $post->caption = $item['caption'] ?? null;
        $post->permalink = $item['permalink'] ?? null;
        $post->timestamp = isset($item['timestamp'])
            ? (DateTimeHelper::toDateTime($item['timestamp']) ?: null)
            : null;
        $post->likeCount = isset($item['like_count']) ? (int) $item['like_count'] : null;
        $post->commentsCount = isset($item['comments_count']) ? (int) $item['comments_count'] : null;
        $post->author = $this->buildAuthor($item);

        [$post->images, $post->videos] = $this->buildMedia($item);

        $post->meta = [
            'mediaType' => $item['media_type'] ?? null,
            'isSharedToFeed' => $item['is_shared_to_feed'] ?? true,
            'mediaProductType' => $item['media_product_type'] ?? null,
            'shortcode' => $item['shortcode'] ?? null,
        ];

        $post->raw = $item;

        return $post;
    }

    /**
     * @return array{0: PostMedia[], 1: PostMedia[]} [images, videos]
     */
    private function buildMedia(array $item): array
    {
        $mediaType = $item['media_type'] ?? null;
        $url = $item['media_url'] ?? null;
        $thumbnailUrl = $item['thumbnail_url'] ?? null;

        if ($mediaType === 'IMAGE' && $url !== null) {
            $media = new PostMedia();
            $media->type = PostMedia::TYPE_IMAGE;
            $media->url = $url;

            return [[$media], []];
        }

        if ($mediaType === 'VIDEO') {
            if ($url !== null) {
                $media = new PostMedia();
                $media->type = PostMedia::TYPE_VIDEO;
                $media->url = $url;
                $media->thumbnailUrl = $thumbnailUrl;

                return [[], [$media]];
            }

            // Meta omits media_url from video responses when the media contains
            // copyrighted content — common on reels with licensed audio, and it
            // can start happening to a post long after it was published. The
            // thumbnail is still served, so fall back to it as an image rather
            // than handing back a post with no media at all: consumers can still
            // render the post and link out to the permalink, which is where the
            // video plays anyway.
            if ($thumbnailUrl !== null) {
                $media = new PostMedia();
                $media->type = PostMedia::TYPE_IMAGE;
                $media->url = $thumbnailUrl;
                $media->thumbnailUrl = $thumbnailUrl;

                return [[$media], []];
            }
        }

        // CAROUSEL_ALBUM: parent has no direct media; children carry it.
        return [[], []];
    }

    private function buildAuthor(array $item): ?PostAuthor
    {
        $owner = $item['owner'] ?? null;
        if (!is_array($owner) || empty($owner)) {
            return null;
        }

        $author = new PostAuthor();
        $author->id = $owner['id'] ?? null;
        $author->handle = $owner['username'] ?? null;
        $author->name = $owner['username'] ?? null;

        return $author;
    }

    private function rawMediaType(Post $post): ?string
    {
        $type = $post->meta['mediaType'] ?? null;
        return is_string($type) ? strtoupper($type) : null;
    }

    // User ID resolution
    // =========================================================================

    /**
     * Get the Instagram user_id for a site's connection.
     *
     * Lookup order: DB column (survives cache flushes) → cache → API call.
     * The DB is back-filled from cache so future lookups can skip the API.
     */
    private function resolveUserId(int $siteId, string $token): ?string
    {
        $connection = SocialStream::$plugin->token->getConnection($siteId, $this->getHandle());

        if ($connection->providerUserId) {
            return $connection->providerUserId;
        }

        $cacheKey = 'social-stream:user-id:' . $this->getHandle() . ':' . $siteId;
        $cached = Craft::$app->cache->get($cacheKey);

        if ($cached !== false) {
            if (!$connection->providerUserId) {
                $connection->providerUserId = $cached;
                $connection->save();
            }
            return $cached;
        }

        try {
            $client = Craft::createGuzzleClient();
            $response = $client->get($this->apiUrl('me'), [
                'query' => [
                    'fields' => 'user_id',
                    'access_token' => $token,
                ],
            ]);

            $data = json_decode($response->getBody()->getContents(), true);
            $userId = $data['user_id'] ?? $data['id'] ?? null;

            if ($userId !== null) {
                Craft::$app->cache->set($cacheKey, $userId, 86400);
                $connection->providerUserId = $userId;
                $connection->save();
            }

            return $userId;
        } catch (ClientException $e) {
            // Delegate rather than hand-rolling the checks: this call runs on every
            // cold miss for a connection with no stored user ID, so it needs the
            // rate-limit cooldown as much as the media edge does.
            $this->handleApiException($e, $siteId);

            return null;
        } catch (GuzzleException $e) {
            SocialStream::error('Failed to get user ID: ' . $e->getMessage());
            return null;
        }
    }

    // Error handling
    // =========================================================================

    /**
     * Record an API error against the connection, and apply the resulting state:
     * a rate-limit cooldown (HTTP 429 / OAuthException code 4), or the re-auth
     * flag (code 190).
     *
     * @return string The error message, for returning to the caller.
     */
    private function handleApiException(ClientException $e, int $siteId): string
    {
        $statusCode = $e->getResponse()->getStatusCode();
        $body = json_decode($e->getResponse()->getBody()->getContents(), true);
        $errorMessage = $body['error']['message'] ?? $e->getMessage();
        $errorCode = $body['error']['code'] ?? null;

        if ($statusCode === 429 || $errorCode === 4) {
            $message = 'Rate limited: ' . $errorMessage;
            $this->enterRateLimitCooldown($siteId);
            $this->recordError($siteId, $message);

            return $message;
        }

        $this->recordError($siteId, $errorMessage);

        // 190 is Meta's catch-all for a token it won't accept — expired, revoked,
        // or invalidated by a password change. None of those recover on their own.
        if ($errorCode === self::ERROR_CODE_INVALID_TOKEN) {
            $this->markNeedsReauth($siteId);
        }

        return $errorMessage;
    }

    // Settings helpers
    // =========================================================================

    private function defaultLimitForSite(int $siteId): int
    {
        $record = SettingsRecord::findOne(['siteId' => $siteId]);
        return $record->defaultLimit ?? SocialStream::$plugin->getSettings()->defaultLimit ?? 25;
    }

    private function excludeNonFeedForSite(int $siteId): bool
    {
        $record = SettingsRecord::findOne(['siteId' => $siteId]);
        if ($record !== null) {
            return (bool) $record->excludeNonFeed;
        }
        return (bool) SocialStream::$plugin->getSettings()->excludeNonFeed;
    }

    private function maxFetchPages(): int
    {
        $config = Craft::$app->config->getConfigFromFile('social-stream');
        return $config['maxFetchPages'] ?? self::DEFAULT_MAX_FETCH_PAGES;
    }

    private function configuredPageSize(): int
    {
        $config = Craft::$app->config->getConfigFromFile('social-stream');

        return (int) ($config['fetchPageSize'] ?? self::DEFAULT_FETCH_PAGE_SIZE);
    }

    /**
     * How many items to request per API page.
     *
     * Deliberately not the caller's limit whenever a filter is active. Filtering
     * happens here rather than at the API, so requesting exactly $limit items
     * hands the filter only $limit candidates — the tighter the limit, the fewer
     * survive it. A homepage asking for 3 feed-shared posts from a reels-heavy
     * account would scan 3 posts, keep one, and render a near-empty grid. Asking
     * for a full page instead gives the filter enough to work with, and usually
     * satisfies the limit from a single call rather than exhausting the page
     * budget.
     *
     * With no filter active the limit *is* the right page size: every item
     * returned is kept, so a larger page is wasted transfer.
     *
     * @param int $configuredPageSize Raw config value; clamped here to the range
     *                                Instagram accepts, so a typo in config can't
     *                                fail every request.
     */
    private function resolvePageSize(int $limit, bool $needsFiltering, int $configuredPageSize): int
    {
        if (!$needsFiltering) {
            return $limit;
        }

        $pageSize = max(1, min($configuredPageSize, self::MAX_FETCH_PAGE_SIZE));

        // A limit larger than the page size still needs pages big enough to reach
        // it, otherwise the page budget is spent before the limit can be met.
        return max($limit, $pageSize);
    }

    private function apiUrl(string $path): string
    {
        return self::API_BASE_URL . '/' . self::API_VERSION . '/' . ltrim($path, '/');
    }
}
