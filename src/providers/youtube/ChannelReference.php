<?php

namespace enovate\socialstream\providers\youtube;

/**
 * Turns whatever an admin pastes into a lookup `channels.list` can answer.
 *
 * The field asks for "the thing in your address bar", because that is what a channel
 * owner actually has: a handle URL, a `/channel/UC…` URL, a legacy `/c/` or `/user/`
 * URL, or — just as often — a link to one of their own videos. All of them identify
 * the channel; only the last needs a second call to get there.
 *
 * Pure parsing, no HTTP: resolution is the provider's job. Ambiguous input returns
 * null rather than a guess, because a handle that resolves to the wrong channel does
 * not fail — it quietly fills the site with a stranger's uploads.
 */
class ChannelReference
{
    /** A canonical `UC…` channel ID: `channels.list?id=`. */
    public const TYPE_ID = 'id';

    /** An `@handle`: `channels.list?forHandle=`. */
    public const TYPE_HANDLE = 'handle';

    /** A legacy username from a `/user/` URL: `channels.list?forUsername=`. */
    public const TYPE_USERNAME = 'username';

    /** A video ID, whose channel is read from `videos.list?part=snippet`. */
    public const TYPE_VIDEO = 'video';

    /**
     * Channel IDs are always `UC` followed by 22 characters of base64url.
     */
    private const CHANNEL_ID_PATTERN = '/^UC[A-Za-z0-9_-]{22}$/';

    /**
     * Handles are 3–30 characters of letters, digits, underscores, dots and hyphens.
     */
    private const HANDLE_PATTERN = '/^[A-Za-z0-9_.-]{3,30}$/';

    /**
     * Video IDs are 11 characters of base64url.
     */
    private const VIDEO_ID_PATTERN = '/^[A-Za-z0-9_-]{11}$/';

    /**
     * Path prefixes that carry a video ID rather than a channel reference.
     */
    private const VIDEO_PATHS = ['shorts', 'embed', 'live', 'v'];

    /**
     * @return array{type: string, value: string}|null Null when the input identifies
     *                                                 no channel, or is too ambiguous
     *                                                 to identify one safely.
     */
    public static function parse(string $input): ?array
    {
        $input = trim($input);

        if ($input === '') {
            return null;
        }

        // Bare identifiers first — they are what the field redisplays once a channel
        // has been resolved, so they have to round-trip.
        if (preg_match(self::CHANNEL_ID_PATTERN, $input)) {
            return self::reference(self::TYPE_ID, $input);
        }

        if (str_starts_with($input, '@')) {
            return self::handle(substr($input, 1));
        }

        return self::fromUrl($input);
    }

    /**
     * What to store in the field so the admin sees back what they typed.
     *
     * Only whitespace is stripped: rewriting a pasted URL into a canonical form would
     * mean the field no longer matches the tab it was copied from, which reads as the
     * setting not having saved.
     */
    public static function canonicalise(string $input): string
    {
        return trim($input);
    }

    /**
     * Present a channel's `snippet.customUrl` as a handle.
     *
     * The Data API is inconsistent about the `@`: newer channels return
     * `@foxesfarmfields`, older ones just `foxesfarmfields`. The CP shows this where
     * an Instagram username goes, so it is normalised to read as a handle either way.
     */
    public static function formatHandle(?string $customUrl): ?string
    {
        $handle = ltrim(trim((string) $customUrl), '@');

        return $handle === '' ? null : '@' . $handle;
    }

    /**
     * Parse a URL, which may arrive without a scheme.
     *
     * @return array{type: string, value: string}|null
     */
    private static function fromUrl(string $input): ?array
    {
        $url = preg_match('#^https?://#i', $input) ? $input : 'https://' . $input;
        $parts = parse_url($url);

        if ($parts === false || !isset($parts['host']) || !self::isYouTubeHost($parts['host'])) {
            return null;
        }

        // youtu.be/<id> carries the video ID as the whole path.
        if (self::isShortLinkHost($parts['host'])) {
            $id = trim($parts['path'] ?? '', '/');

            return preg_match(self::VIDEO_ID_PATTERN, $id) ? self::reference(self::TYPE_VIDEO, $id) : null;
        }

        $segments = array_values(array_filter(explode('/', $parts['path'] ?? ''), static fn($s) => $s !== ''));
        $first = $segments[0] ?? null;

        if ($first === null) {
            return null;
        }

        if (str_starts_with($first, '@')) {
            return self::handle(substr($first, 1));
        }

        if ($first === 'watch') {
            parse_str($parts['query'] ?? '', $query);
            $id = is_string($query['v'] ?? null) ? $query['v'] : '';

            return preg_match(self::VIDEO_ID_PATTERN, $id) ? self::reference(self::TYPE_VIDEO, $id) : null;
        }

        if (in_array($first, self::VIDEO_PATHS, true)) {
            $id = $segments[1] ?? '';

            return preg_match(self::VIDEO_ID_PATTERN, $id) ? self::reference(self::TYPE_VIDEO, $id) : null;
        }

        if ($first === 'channel') {
            $id = $segments[1] ?? '';

            return preg_match(self::CHANNEL_ID_PATTERN, $id) ? self::reference(self::TYPE_ID, $id) : null;
        }

        if ($first === 'user') {
            $name = $segments[1] ?? '';

            return preg_match(self::HANDLE_PATTERN, $name)
                ? self::reference(self::TYPE_USERNAME, $name)
                : null;
        }

        // The legacy `/c/CustomName` URL has no `channels.list` parameter. Google
        // auto-assigned every channel a handle in 2023 and it usually matches the old
        // custom name, so it is worth one unit as a handle. The alternative,
        // `search.list`, costs 100 units and matches fuzzily — it would sometimes
        // return a different channel with a similar name, which is the one outcome
        // worth avoiding entirely.
        if ($first === 'c') {
            return self::handle($segments[1] ?? '');
        }

        return null;
    }

    /**
     * @return array{type: string, value: string}|null
     */
    private static function handle(string $handle): ?array
    {
        return preg_match(self::HANDLE_PATTERN, $handle)
            ? self::reference(self::TYPE_HANDLE, $handle)
            : null;
    }

    /**
     * @return array{type: string, value: string}
     */
    private static function reference(string $type, string $value): array
    {
        return ['type' => $type, 'value' => $value];
    }

    private static function isYouTubeHost(string $host): bool
    {
        $host = strtolower($host);

        return $host === 'youtube.com'
            || str_ends_with($host, '.youtube.com')
            || self::isShortLinkHost($host);
    }

    private static function isShortLinkHost(string $host): bool
    {
        return strtolower($host) === 'youtu.be';
    }
}
