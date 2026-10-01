<?php

namespace enovate\socialstream\tests\unit;

use enovate\socialstream\models\PostMedia;
use enovate\socialstream\providers\youtube\VideoMapper;
use PHPUnit\Framework\TestCase;

/**
 * Tests the thumbnail fallback chain.
 *
 * Only `default`, `medium` and `high` are always present. `standard` and `maxres`
 * depend on the source upload and whether a custom thumbnail was set, so picking
 * blindly by name would hand templates a broken image for most videos.
 */
class YouTubeThumbnailTest extends TestCase
{
    private function thumbnail(string $size): array
    {
        return [$size => ['url' => "https://i.ytimg.com/vi/x/{$size}.jpg", 'width' => 100, 'height' => 50]];
    }

    private function select(array $thumbnails): ?PostMedia
    {
        return (new VideoMapper())->selectThumbnail($thumbnails);
    }

    public function testPrefersMaxresWhenPresent(): void
    {
        $media = $this->select(array_merge(
            $this->thumbnail('default'),
            $this->thumbnail('medium'),
            $this->thumbnail('high'),
            $this->thumbnail('standard'),
            $this->thumbnail('maxres'),
        ));

        self::assertSame('https://i.ytimg.com/vi/x/maxres.jpg', $media->url);
    }

    public function testFallsBackThroughTheChain(): void
    {
        $media = $this->select(array_merge(
            $this->thumbnail('default'),
            $this->thumbnail('medium'),
            $this->thumbnail('high'),
        ));

        self::assertSame('https://i.ytimg.com/vi/x/high.jpg', $media->url);
    }

    public function testFallsAllTheWayToDefault(): void
    {
        $media = $this->select($this->thumbnail('default'));

        self::assertSame('https://i.ytimg.com/vi/x/default.jpg', $media->url);
    }

    public function testSkipsASizeWithNoUrl(): void
    {
        $thumbnails = array_merge($this->thumbnail('high'), ['maxres' => ['width' => 1280]]);

        self::assertSame('https://i.ytimg.com/vi/x/high.jpg', $this->select($thumbnails)->url);
    }

    public function testNoThumbnailsMeansNoMedia(): void
    {
        self::assertNull($this->select([]));
    }

    public function testCarriesTheDimensionsAndAMediumThumbnail(): void
    {
        $media = $this->select(array_merge($this->thumbnail('medium'), $this->thumbnail('maxres')));

        self::assertSame(PostMedia::TYPE_IMAGE, $media->type);
        self::assertSame(100, $media->width);
        self::assertSame(50, $media->height);
        self::assertSame('https://i.ytimg.com/vi/x/medium.jpg', $media->thumbnailUrl);
    }
}
