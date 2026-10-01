<?php

namespace enovate\socialstream\providers\youtube;

use Craft;
use craft\helpers\Json;
use enovate\socialstream\SocialStream;
use GuzzleHttp\Client;
use GuzzleHttp\Pool;
use Psr\Http\Message\ResponseInterface;

/**
 * Works out which videos are Shorts.
 *
 * The Data API has no field for it (issuetracker.google.com/issues/232112727), so
 * this asks youtube.com. Two independent signals, neither documented as a
 * contract, both free of API quota:
 *
 * 1. **oEmbed at the `/shorts/` URL** returns portrait dimensions (113x200) for a
 *    genuine Short and landscape (200x113) for everything else. Asking about a
 *    standard video at a `/shorts/` URL still returns landscape, so the URL form
 *    cannot produce a false positive. The `/watch?v=` form is useless here — it
 *    reports landscape for Shorts too.
 * 2. **The `/shorts/` page itself**, with redirects disabled: 200 for a Short,
 *    303 to `/watch` for a standard video. Heavier (a full HTML page), so it only
 *    runs for IDs oEmbed could not answer — private videos and those with
 *    embedding disabled, which oEmbed refuses.
 *
 * An ID that neither signal resolves is left out of the returned map rather than
 * defaulted, so a transient network failure is never cached as a permanent
 * "not a Short". Its caller treats it as a regular video for that fetch only.
 */
class ShortsResolver
{
    /**
     * Per-video, untagged, and never expiring: a video's Shorts status cannot
     * change. Untagged matters — a WebSub notification invalidates the site's feed
     * tag, and taking these with it would re-run a lookup for the whole feed on
     * every upload.
     */
    private const CACHE_PREFIX = 'social-stream:youtube-short:';

    private const OEMBED_URL = 'https://www.youtube.com/oembed';

    private const SHORTS_URL = 'https://www.youtube.com/shorts/';

    private const CONCURRENCY = 10;

    private const TIMEOUT = 5;

    /**
     * Seconds a single fetch may spend on lookups in total. A cold 50-video page is
     * 50 outbound requests on a request someone is waiting for, so the budget caps
     * the tail: IDs not reached are left unresolved and picked up by the next fetch,
     * which is usually the background refresh job.
     */
    private const DEFAULT_BUDGET = 8;

    /**
     * @param string[] $videoIds
     * @return array<string, bool> videoId => isShort, omitting anything unresolved.
     */
    public function resolve(array $videoIds): array
    {
        $videoIds = array_values(array_unique(array_filter($videoIds)));

        if ($videoIds === []) {
            return [];
        }

        [$resolved, $unknown] = $this->readCache($videoIds);

        if ($unknown === [] || !$this->enabled()) {
            return $resolved;
        }

        $deadline = microtime(true) + $this->budget();
        $resolved += $this->lookupOembed($unknown, $deadline);

        $remaining = array_values(array_diff($unknown, array_keys($resolved)));

        if ($remaining !== [] && $this->fallbackEnabled()) {
            $resolved += $this->lookupShortsPage($remaining, $deadline);
        }

        $stillUnknown = count(array_diff($unknown, array_keys($resolved)));

        if ($stillUnknown > 0) {
            SocialStream::info(
                'Shorts status unresolved for ' . $stillUnknown . ' of ' . count($videoIds)
                . ' video(s); treating them as regular videos for this fetch.'
            );
        }

        return $resolved;
    }

    /**
     * @param string[] $videoIds
     * @return array{0: array<string, bool>, 1: string[]} [cached results, IDs still to look up]
     */
    private function readCache(array $videoIds): array
    {
        $resolved = [];
        $unknown = [];

        foreach ($videoIds as $videoId) {
            // Stored as 1/0 rather than a bool: cache->get() returns false on a
            // miss, which would be indistinguishable from a cached "not a Short".
            $cached = Craft::$app->cache->get(self::CACHE_PREFIX . $videoId);

            if ($cached === false) {
                $unknown[] = $videoId;
            } else {
                $resolved[$videoId] = (bool) $cached;
            }
        }

        return [$resolved, $unknown];
    }

    /**
     * @param string[] $videoIds
     * @return array<string, bool>
     */
    private function lookupOembed(array $videoIds, float $deadline): array
    {
        return $this->runPool(
            $videoIds,
            $deadline,
            fn(Client $client, string $videoId) => $client->getAsync(self::OEMBED_URL, [
                'query' => [
                    'url' => self::SHORTS_URL . $videoId,
                    'format' => 'json',
                ],
            ]),
            function (ResponseInterface $response): ?bool {
                if ($response->getStatusCode() !== 200) {
                    return null;
                }

                // decodeIfJson() hands back the raw string when the body isn't JSON,
                // so it can't be indexed without checking.
                $data = Json::decodeIfJson((string) $response->getBody());

                if (!is_array($data)) {
                    return null;
                }

                return self::classifyOembed(
                    (int) ($data['width'] ?? 0),
                    (int) ($data['height'] ?? 0),
                );
            },
        );
    }

    /**
     * @param string[] $videoIds
     * @return array<string, bool>
     */
    private function lookupShortsPage(array $videoIds, float $deadline): array
    {
        return $this->runPool(
            $videoIds,
            $deadline,
            fn(Client $client, string $videoId) => $client->getAsync(self::SHORTS_URL . $videoId, [
                'allow_redirects' => false,
            ]),
            fn(ResponseInterface $response): ?bool => self::classifyShortsPage(
                $response->getStatusCode(),
                $response->getHeaderLine('Location'),
            ),
        );
    }

    /**
     * The oEmbed signal: portrait means Short.
     *
     * Missing or zero dimensions are no answer at all — oEmbed returns an error
     * document for a private video or one with embedding disabled, and reading that
     * as landscape would mark it a regular video forever.
     *
     * @return bool|null Null when the response says nothing either way.
     */
    public static function classifyOembed(int $width, int $height): ?bool
    {
        if ($width <= 0 || $height <= 0) {
            return null;
        }

        return $height > $width;
    }

    /**
     * The `/shorts/` page signal, with redirects disabled: 200 is a Short, a redirect
     * to `/watch` is a regular video.
     *
     * Any other redirect — a cookie-consent interstitial, a region block — proves
     * nothing, so it leaves the video unresolved rather than guessing.
     *
     * @return bool|null Null when the response says nothing either way.
     */
    public static function classifyShortsPage(int $statusCode, string $location): ?bool
    {
        if ($statusCode === 200) {
            return true;
        }

        if ($statusCode < 300 || $statusCode >= 400) {
            return null;
        }

        return str_contains($location, '/watch') ? false : null;
    }

    /**
     * Run one signal across the given IDs concurrently, caching each answer.
     *
     * @param string[] $videoIds
     * @param callable(Client, string): \GuzzleHttp\Promise\PromiseInterface $request
     * @param callable(ResponseInterface): ?bool $classify Null means "no answer".
     * @return array<string, bool>
     */
    private function runPool(array $videoIds, float $deadline, callable $request, callable $classify): array
    {
        $results = [];
        $skipped = 0;

        $client = Craft::createGuzzleClient([
            'timeout' => self::TIMEOUT,
            // Non-2xx responses are answers here, not exceptions.
            'http_errors' => false,
        ]);

        $requests = function () use ($videoIds, $client, $request, $deadline, &$skipped) {
            foreach ($videoIds as $videoId) {
                if (microtime(true) >= $deadline) {
                    $skipped++;
                    continue;
                }

                yield $videoId => fn() => $request($client, $videoId);
            }
        };

        (new Pool($client, $requests(), [
            'concurrency' => self::CONCURRENCY,
            'fulfilled' => function (ResponseInterface $response, string $videoId) use (&$results, $classify) {
                $isShort = $classify($response);

                if ($isShort === null) {
                    return;
                }

                $results[$videoId] = $isShort;
                $this->remember($videoId, $isShort);
            },
            // A rejected request leaves the ID unresolved on purpose. Caching a
            // timeout as "not a Short" would be permanent.
            'rejected' => function () {
            },
        ]))->promise()->wait();

        if ($skipped > 0) {
            SocialStream::info(
                'Shorts detection ran out of its ' . $this->budget() . '-second budget with '
                . $skipped . ' lookup(s) left; they will be retried on the next fetch.'
            );
        }

        return $results;
    }

    private function remember(string $videoId, bool $isShort): void
    {
        Craft::$app->cache->set(self::CACHE_PREFIX . $videoId, (int) $isShort, 0);
    }

    private function enabled(): bool
    {
        return (bool) ($this->config()['shortsDetection'] ?? true);
    }

    private function fallbackEnabled(): bool
    {
        return (bool) ($this->config()['shortsRedirectFallback'] ?? true);
    }

    private function budget(): int
    {
        return max(1, (int) ($this->config()['shortsLookupBudget'] ?? self::DEFAULT_BUDGET));
    }

    private function config(): array
    {
        return Craft::$app->config->getConfigFromFile('social-stream');
    }
}
