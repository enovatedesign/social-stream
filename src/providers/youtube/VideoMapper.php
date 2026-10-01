<?php

namespace enovate\socialstream\providers\youtube;

use craft\helpers\DateTimeHelper;
use enovate\socialstream\models\Post;
use enovate\socialstream\models\PostAuthor;
use enovate\socialstream\models\PostMedia;
use enovate\socialstream\providers\YouTubeProvider;
use enovate\socialstream\SocialStream;
use DateInterval;
use Exception;

/**
 * Maps a `videos.list` item onto the shared {@see Post} model.
 *
 * Pure translation — no HTTP, no cache, no connection state — so the mapping the
 * whole provider hangs off can be asserted directly.
 */
class VideoMapper
{
    /**
     * Thumbnail sizes in descending preference. Only `default`, `medium` and `high`
     * are always present; `standard` and `maxres` appear for larger source uploads
     * and custom thumbnails.
     */
    public const THUMBNAIL_PREFERENCE = ['maxres', 'standard', 'high', 'medium', 'default'];

    public const WATCH_URL = 'https://www.youtube.com/watch?v=';

    public const SHORTS_URL = 'https://www.youtube.com/shorts/';

    public const EMBED_URL = 'https://www.youtube.com/embed/';

    public const CHANNEL_URL = 'https://www.youtube.com/channel/';

    /**
     * @param array $video A `videos.list` item with snippet, contentDetails,
     *                     statistics and status parts.
     * @param bool $isShort As determined by {@see ShortsResolver}. The Data API has
     *                      no field for it.
     */
    public function map(array $video, bool $isShort): Post
    {
        $snippet = $video['snippet'] ?? [];
        $contentDetails = $video['contentDetails'] ?? [];
        $statistics = $video['statistics'] ?? [];
        $status = $video['status'] ?? [];

        $post = new Post();
        $post->id = isset($video['id']) ? (string) $video['id'] : null;
        $post->provider = YouTubeProvider::handle();

        // The title is the closest analogue to Instagram's caption: it is the line a
        // template renders next to the thumbnail. The description is long-form and
        // stays in meta.
        $post->caption = $snippet['title'] ?? null;
        $post->permalink = $post->id === null
            ? null
            : ($isShort ? self::SHORTS_URL . $post->id : self::WATCH_URL . $post->id);
        // `publishedAt` is RFC 3339 in UTC, and it is left that way rather than
        // converted to the system timezone: Twig's `|date` filter formats in the app's
        // timezone whatever the object carries, so the conversion changes no rendered
        // output — and skipping it keeps this class free of application state.
        $post->timestamp = isset($snippet['publishedAt'])
            ? (DateTimeHelper::toDateTime($snippet['publishedAt'], false, false) ?: null)
            : null;

        // Statistics come back as strings, and are absent entirely when the channel
        // owner has hidden them — null rather than 0, so a template can tell
        // "hidden" from "none".
        $post->likeCount = isset($statistics['likeCount']) ? (int) $statistics['likeCount'] : null;
        $post->commentsCount = isset($statistics['commentCount']) ? (int) $statistics['commentCount'] : null;
        $post->author = $this->buildAuthor($snippet);

        $thumbnail = $this->selectThumbnail($snippet['thumbnails'] ?? []);
        $post->images = $thumbnail === null ? [] : [$thumbnail];

        // Deliberately empty: YouTube exposes no direct video file URL. Playback is
        // the embed iframe, so an entry here with a null url would make
        // `videos|length` lie to every template that guards on it.
        $post->videos = [];
        $post->children = [];

        $durationSeconds = $this->parseDuration($contentDetails['duration'] ?? 'PT0S');

        $post->meta = [
            'title' => $snippet['title'] ?? null,
            'description' => $snippet['description'] ?? null,
            'duration' => $contentDetails['duration'] ?? null,
            'durationSeconds' => $durationSeconds,
            'durationFormatted' => $this->formatDuration($durationSeconds),
            'definition' => $contentDetails['definition'] ?? null,
            'viewCount' => isset($statistics['viewCount']) ? (int) $statistics['viewCount'] : null,
            'tags' => $snippet['tags'] ?? [],
            'categoryId' => $snippet['categoryId'] ?? null,
            'privacyStatus' => $status['privacyStatus'] ?? null,
            'embeddable' => $status['embeddable'] ?? true,
            'madeForKids' => $status['madeForKids'] ?? false,
            'isShort' => $isShort,
            'mediaType' => $isShort ? YouTubeProvider::MEDIA_TYPE_SHORT : YouTubeProvider::MEDIA_TYPE_VIDEO,
            'channelId' => $snippet['channelId'] ?? null,
            'channelTitle' => $snippet['channelTitle'] ?? null,
            'thumbnails' => $snippet['thumbnails'] ?? [],
            'embedUrl' => $post->id === null ? null : self::EMBED_URL . $post->id,
        ];

        $post->raw = $video;

        return $post;
    }

    /**
     * Pick the largest thumbnail the response actually carries.
     */
    public function selectThumbnail(array $thumbnails): ?PostMedia
    {
        foreach (self::THUMBNAIL_PREFERENCE as $size) {
            $url = $thumbnails[$size]['url'] ?? null;

            if (!is_string($url) || $url === '') {
                continue;
            }

            $media = new PostMedia();
            $media->type = PostMedia::TYPE_IMAGE;
            $media->url = $url;
            $media->thumbnailUrl = $thumbnails['medium']['url'] ?? $url;
            $media->width = isset($thumbnails[$size]['width']) ? (int) $thumbnails[$size]['width'] : null;
            $media->height = isset($thumbnails[$size]['height']) ? (int) $thumbnails[$size]['height'] : null;

            return $media;
        }

        return null;
    }

    /**
     * Convert an ISO 8601 duration to whole seconds.
     *
     * DateInterval rather than a regex: long livestream replays carry a day
     * component, and hand-rolled patterns tend to miss it.
     */
    public function parseDuration(?string $iso8601): int
    {
        if ($iso8601 === null || $iso8601 === '') {
            return 0;
        }

        try {
            $interval = new DateInterval($iso8601);
        } catch (Exception $e) {
            SocialStream::warning('Failed to parse YouTube duration: ' . $iso8601);

            return 0;
        }

        return ($interval->y * 31536000)
            + ($interval->m * 2592000)
            + ($interval->d * 86400)
            + ($interval->h * 3600)
            + ($interval->i * 60)
            + $interval->s;
    }

    /**
     * Seconds as a clock duration: `0:59`, `1:30`, `2:15:03`.
     */
    public function formatDuration(int $seconds): string
    {
        $seconds = max(0, $seconds);
        $hours = intdiv($seconds, 3600);
        $minutes = intdiv($seconds % 3600, 60);
        $remainder = $seconds % 60;

        if ($hours > 0) {
            return sprintf('%d:%02d:%02d', $hours, $minutes, $remainder);
        }

        return sprintf('%d:%02d', $minutes, $remainder);
    }

    private function buildAuthor(array $snippet): ?PostAuthor
    {
        $channelId = $snippet['channelId'] ?? null;
        $channelTitle = $snippet['channelTitle'] ?? null;

        if ($channelId === null && $channelTitle === null) {
            return null;
        }

        $author = new PostAuthor();
        $author->id = $channelId;
        $author->name = $channelTitle;

        // The Data API's video response carries no channel handle, and inventing one
        // from the title would be wrong — the display name is the honest answer, and
        // it is what the Instagram provider puts here too.
        $author->handle = $channelTitle;
        $author->url = $channelId === null ? null : self::CHANNEL_URL . $channelId;

        return $author;
    }
}
