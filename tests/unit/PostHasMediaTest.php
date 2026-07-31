<?php

namespace enovate\socialstream\tests\unit;

use enovate\socialstream\models\Post;
use enovate\socialstream\models\PostMedia;
use PHPUnit\Framework\TestCase;

/**
 * Tests Post::hasMedia(), which templates use as a "can I render this post?"
 * guard. Carousel albums are the trap: they carry no media of their own, so a
 * naive images/videos check would drop every album on the floor.
 */
class PostHasMediaTest extends TestCase
{
    private function image(): PostMedia
    {
        $media = new PostMedia();
        $media->type = PostMedia::TYPE_IMAGE;
        $media->url = 'https://example.com/photo.jpg';

        return $media;
    }

    public function testPostWithAnImageHasMedia(): void
    {
        $post = new Post();
        $post->images = [$this->image()];

        self::assertTrue($post->hasMedia());
    }

    public function testPostWithNothingAttachedHasNoMedia(): void
    {
        self::assertFalse((new Post())->hasMedia());
    }

    public function testCarouselAlbumHasMediaViaItsChildren(): void
    {
        $child = new Post();
        $child->images = [$this->image()];

        $album = new Post();
        $album->children = [$child];

        self::assertTrue($album->hasMedia());
    }

    public function testCarouselAlbumWithEmptyChildrenHasNoMedia(): void
    {
        $album = new Post();
        $album->children = [new Post()];

        self::assertFalse($album->hasMedia());
    }
}
