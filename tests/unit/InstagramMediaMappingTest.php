<?php

namespace enovate\socialstream\tests\unit;

use enovate\socialstream\models\PostMedia;
use enovate\socialstream\providers\InstagramProvider;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

/**
 * Tests how raw Instagram media objects map onto a Post's images/videos.
 *
 * The case that matters most is a video with no `media_url`: Meta omits it when
 * the media contains copyrighted content, which can start happening to a post
 * long after it was published. Mapping that to no media at all leaves consumers
 * with an unrenderable post, so the thumbnail has to stand in.
 */
class InstagramMediaMappingTest extends TestCase
{
    /**
     * @return array{0: PostMedia[], 1: PostMedia[]} [images, videos]
     */
    private function buildMedia(array $item): array
    {
        $method = new ReflectionMethod(InstagramProvider::class, 'buildMedia');

        return $method->invoke(new InstagramProvider(), $item);
    }

    public function testImageMapsToASingleImage(): void
    {
        [$images, $videos] = $this->buildMedia([
            'media_type' => 'IMAGE',
            'media_url' => 'https://example.com/photo.jpg',
        ]);

        self::assertCount(1, $images);
        self::assertSame([], $videos);
        self::assertSame(PostMedia::TYPE_IMAGE, $images[0]->type);
        self::assertSame('https://example.com/photo.jpg', $images[0]->url);
    }

    public function testVideoMapsToASingleVideoWithItsThumbnail(): void
    {
        [$images, $videos] = $this->buildMedia([
            'media_type' => 'VIDEO',
            'media_url' => 'https://example.com/reel.mp4',
            'thumbnail_url' => 'https://example.com/reel.jpg',
        ]);

        self::assertSame([], $images);
        self::assertCount(1, $videos);
        self::assertSame(PostMedia::TYPE_VIDEO, $videos[0]->type);
        self::assertSame('https://example.com/reel.mp4', $videos[0]->url);
        self::assertSame('https://example.com/reel.jpg', $videos[0]->thumbnailUrl);
    }

    public function testVideoWithNoMediaUrlFallsBackToItsThumbnailAsAnImage(): void
    {
        [$images, $videos] = $this->buildMedia([
            'media_type' => 'VIDEO',
            'thumbnail_url' => 'https://example.com/reel.jpg',
        ]);

        self::assertSame([], $videos, 'A video with no URL must not be offered as playable.');
        self::assertCount(1, $images);
        self::assertSame(PostMedia::TYPE_IMAGE, $images[0]->type);
        self::assertSame('https://example.com/reel.jpg', $images[0]->url);
    }

    public function testVideoWithNeitherUrlMapsToNoMedia(): void
    {
        [$images, $videos] = $this->buildMedia(['media_type' => 'VIDEO']);

        self::assertSame([], $images);
        self::assertSame([], $videos);
    }

    public function testCarouselAlbumCarriesNoMediaOfItsOwn(): void
    {
        [$images, $videos] = $this->buildMedia([
            'media_type' => 'CAROUSEL_ALBUM',
            'media_url' => 'https://example.com/cover.jpg',
        ]);

        self::assertSame([], $images, 'Album media lives on the children.');
        self::assertSame([], $videos);
    }
}
