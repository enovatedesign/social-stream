<?php

namespace enovate\socialstream\tests\unit;

use enovate\socialstream\models\PostMedia;
use enovate\socialstream\providers\youtube\VideoMapper;
use PHPUnit\Framework\TestCase;

/**
 * Tests how a raw `videos.list` item becomes a Post.
 *
 * The cases that matter: a Short has to get the `/shorts/` permalink (the `/watch`
 * form redirects, and templates render the href directly), statistics a channel has
 * hidden have to come back null rather than zero, and a video with no direct file URL
 * must not be offered as playable media.
 */
class YouTubeVideoMappingTest extends TestCase
{
    private function mapper(): VideoMapper
    {
        return new VideoMapper();
    }

    private function video(array $overrides = []): array
    {
        return array_replace_recursive([
            'id' => 'dQw4w9WgXcQ',
            'snippet' => [
                'publishedAt' => '2026-03-03T12:00:00Z',
                'channelId' => 'UCabc123',
                'title' => 'A video title',
                'description' => 'The long form description.',
                'channelTitle' => 'Test Channel',
                'categoryId' => '22',
                'tags' => ['one', 'two'],
                'thumbnails' => [
                    'default' => ['url' => 'https://i.ytimg.com/vi/x/default.jpg', 'width' => 120, 'height' => 90],
                    'medium' => ['url' => 'https://i.ytimg.com/vi/x/mqdefault.jpg', 'width' => 320, 'height' => 180],
                    'high' => ['url' => 'https://i.ytimg.com/vi/x/hqdefault.jpg', 'width' => 480, 'height' => 360],
                ],
            ],
            'contentDetails' => [
                'duration' => 'PT1M30S',
                'definition' => 'hd',
            ],
            'statistics' => [
                'viewCount' => '1234',
                'likeCount' => '56',
                'commentCount' => '7',
            ],
            'status' => [
                'privacyStatus' => 'public',
                'embeddable' => true,
                'madeForKids' => false,
            ],
        ], $overrides);
    }

    public function testMapsTheCoreFields(): void
    {
        $post = $this->mapper()->map($this->video(), false);

        self::assertSame('dQw4w9WgXcQ', $post->id);
        self::assertSame('youtube', $post->provider);
        self::assertSame('A video title', $post->caption);
        self::assertSame('https://www.youtube.com/watch?v=dQw4w9WgXcQ', $post->permalink);
        self::assertSame(56, $post->likeCount);
        self::assertSame(7, $post->commentsCount);
        self::assertSame('2026-03-03', $post->timestamp->format('Y-m-d'));
    }

    public function testAShortGetsTheShortsPermalinkAndMediaType(): void
    {
        $post = $this->mapper()->map($this->video(), true);

        self::assertSame('https://www.youtube.com/shorts/dQw4w9WgXcQ', $post->permalink);
        self::assertTrue($post->meta['isShort']);
        self::assertSame('SHORT', $post->meta['mediaType']);
    }

    public function testARegularVideoIsMarkedAsOne(): void
    {
        $post = $this->mapper()->map($this->video(), false);

        self::assertFalse($post->meta['isShort']);
        self::assertSame('VIDEO', $post->meta['mediaType']);
    }

    public function testTheThumbnailBecomesTheOnlyMedia(): void
    {
        $post = $this->mapper()->map($this->video(), false);

        self::assertCount(1, $post->images);
        self::assertSame(PostMedia::TYPE_IMAGE, $post->images[0]->type);
        self::assertSame(
            [],
            $post->videos,
            'YouTube exposes no direct file URL, so nothing may be offered as playable.'
        );
        self::assertTrue($post->hasMedia());
    }

    public function testMetaCarriesTheYouTubeSpecificFields(): void
    {
        $post = $this->mapper()->map($this->video(), false);

        self::assertSame('A video title', $post->meta['title']);
        self::assertSame('The long form description.', $post->meta['description']);
        self::assertSame('PT1M30S', $post->meta['duration']);
        self::assertSame(90, $post->meta['durationSeconds']);
        self::assertSame('1:30', $post->meta['durationFormatted']);
        self::assertSame('hd', $post->meta['definition']);
        self::assertSame(1234, $post->meta['viewCount']);
        self::assertSame(['one', 'two'], $post->meta['tags']);
        self::assertSame('22', $post->meta['categoryId']);
        self::assertSame('public', $post->meta['privacyStatus']);
        self::assertTrue($post->meta['embeddable']);
        self::assertFalse($post->meta['madeForKids']);
        self::assertSame('UCabc123', $post->meta['channelId']);
        self::assertSame('Test Channel', $post->meta['channelTitle']);
        self::assertSame('https://www.youtube.com/embed/dQw4w9WgXcQ', $post->meta['embedUrl']);
        self::assertArrayHasKey('high', $post->meta['thumbnails']);
    }

    public function testHiddenStatisticsMapToNullRatherThanZero(): void
    {
        $video = $this->video();
        unset($video['statistics']);

        $post = $this->mapper()->map($video, false);

        self::assertNull($post->likeCount, 'A hidden like count is not zero likes.');
        self::assertNull($post->commentsCount);
        self::assertNull($post->meta['viewCount']);
    }

    public function testEmbeddingDefaultsToAllowedWhenStatusIsAbsent(): void
    {
        $video = $this->video();
        unset($video['status']);

        $post = $this->mapper()->map($video, false);

        self::assertTrue(
            $post->meta['embeddable'],
            'Without a status part the iframe is the only way to play it, so assuming it works beats hiding it.'
        );
    }

    public function testTheAuthorComesFromTheChannel(): void
    {
        $post = $this->mapper()->map($this->video(), false);

        self::assertNotNull($post->author);
        self::assertSame('UCabc123', $post->author->id);
        self::assertSame('Test Channel', $post->author->name);
        self::assertSame('https://www.youtube.com/channel/UCabc123', $post->author->url);
    }

    public function testRawKeepsTheOriginalPayload(): void
    {
        $video = $this->video();

        self::assertSame($video, $this->mapper()->map($video, false)->raw);
    }
}
