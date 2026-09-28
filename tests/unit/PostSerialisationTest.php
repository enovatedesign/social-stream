<?php

namespace enovate\socialstream\tests\unit;

use enovate\socialstream\models\Post;
use enovate\socialstream\providers\youtube\VideoMapper;
use PHPUnit\Framework\TestCase;

/**
 * Tests that a post survives the cache round trip.
 *
 * Stream responses are stored as plain arrays and rebuilt on the way out, so
 * anything toArray() drops is gone for every cached read — which is most of them.
 * A YouTube post is the demanding case: everything provider-specific about it lives
 * in `meta`, including the flags templates branch on.
 */
class PostSerialisationTest extends TestCase
{
    private function youTubePost(): Post
    {
        return (new VideoMapper())->map([
            'id' => 'dQw4w9WgXcQ',
            'snippet' => [
                'publishedAt' => '2026-03-03T12:00:00Z',
                'channelId' => 'UCabc123',
                'channelTitle' => 'Test Channel',
                'title' => 'A Short',
                'description' => 'Body text.',
                'tags' => ['one'],
                'thumbnails' => [
                    'high' => ['url' => 'https://i.ytimg.com/vi/x/hqdefault.jpg', 'width' => 480, 'height' => 360],
                ],
            ],
            'contentDetails' => ['duration' => 'PT45S', 'definition' => 'hd'],
            'statistics' => ['viewCount' => '9', 'likeCount' => '8', 'commentCount' => '7'],
            'status' => ['privacyStatus' => 'public', 'embeddable' => false, 'madeForKids' => true],
        ], true);
    }

    public function testAYouTubePostRoundTripsLosslessly(): void
    {
        $original = $this->youTubePost();
        $restored = Post::fromArray($original->toArray());

        self::assertSame($original->toArray(), $restored->toArray());
    }

    public function testTheRestoredPostKeepsTheFieldsTemplatesBranchOn(): void
    {
        $restored = Post::fromArray($this->youTubePost()->toArray());

        self::assertSame('youtube', $restored->provider);
        self::assertSame('https://www.youtube.com/shorts/dQw4w9WgXcQ', $restored->permalink);
        self::assertTrue($restored->meta['isShort']);
        self::assertSame('SHORT', $restored->meta['mediaType']);
        self::assertFalse($restored->meta['embeddable']);
        self::assertTrue($restored->meta['madeForKids']);
        self::assertSame('0:45', $restored->meta['durationFormatted']);
        self::assertSame(9, $restored->meta['viewCount']);
        self::assertSame('https://i.ytimg.com/vi/x/hqdefault.jpg', $restored->images[0]->url);
        self::assertSame(480, $restored->images[0]->width);
    }

    public function testTheTimestampSurvivesAsTheSameInstant(): void
    {
        $original = $this->youTubePost();
        $restored = Post::fromArray($original->toArray());

        self::assertSame(
            $original->timestamp->getTimestamp(),
            $restored->timestamp->getTimestamp()
        );
    }

    public function testTheRawPayloadIsPreservedForTemplateEscapeHatches(): void
    {
        $original = $this->youTubePost();
        $restored = Post::fromArray($original->toArray());

        self::assertSame($original->raw, $restored->raw);
    }
}
