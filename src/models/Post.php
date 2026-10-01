<?php

namespace enovate\socialstream\models;

use craft\base\Model;
use craft\helpers\DateTimeHelper;
use DateTime;

/**
 * Provider-agnostic post model.
 *
 * Providers map their native API response into this shape at the boundary.
 * Provider-specific fields that don't fit the common shape go into `$meta`;
 * the raw API response is preserved in `$raw` for debugging and escape-hatch
 * access in templates.
 */
class Post extends Model
{
    public ?string $id = null;

    public ?string $provider = null;

    public ?string $caption = null;

    public ?string $permalink = null;

    public ?DateTime $timestamp = null;

    public ?int $likeCount = null;

    public ?int $commentsCount = null;

    public ?PostAuthor $author = null;

    /** @var PostMedia[] */
    public array $images = [];

    /** @var PostMedia[] */
    public array $videos = [];

    /** @var Post[] */
    public array $children = [];

    public array $meta = [];

    public array $raw = [];

    /**
     * Whether the post has anything renderable attached — an image, a video, or
     * a child that has one.
     *
     * Carousel albums carry no media of their own, so the children have to count
     * here for templates to be able to use this as a "can I render this post?"
     * guard without silently dropping every album.
     */
    public function hasMedia(): bool
    {
        if ($this->images !== [] || $this->videos !== []) {
            return true;
        }

        foreach ($this->children as $child) {
            if ($child->hasMedia()) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param array $fields Unused; present to match craft\base\Model signature.
     * @param array $expand Unused; present to match craft\base\Model signature.
     * @param bool $recursive Unused; present to match craft\base\Model signature.
     */
    public function toArray(array $fields = [], array $expand = [], $recursive = true): array
    {
        return [
            'id' => $this->id,
            'provider' => $this->provider,
            'caption' => $this->caption,
            'permalink' => $this->permalink,
            'timestamp' => $this->timestamp?->format(DateTime::ATOM),
            'likeCount' => $this->likeCount,
            'commentsCount' => $this->commentsCount,
            'author' => $this->author?->toArray(),
            'images' => array_map(fn(PostMedia $m) => $m->toArray(), $this->images),
            'videos' => array_map(fn(PostMedia $m) => $m->toArray(), $this->videos),
            'children' => array_map(fn(Post $c) => $c->toArray(), $this->children),
            'meta' => $this->meta,
            'raw' => $this->raw,
        ];
    }

    public static function fromArray(array $data): self
    {
        $post = new self();
        $post->id = $data['id'] ?? null;
        $post->provider = $data['provider'] ?? null;
        $post->caption = $data['caption'] ?? null;
        $post->permalink = $data['permalink'] ?? null;
        // Rebuilt at the offset it was serialised with, rather than converted to the
        // system timezone: the instant is the same either way, Twig's `|date` filter
        // formats in the app's timezone regardless, and leaving it alone makes the
        // round trip through the cache byte-for-byte reversible.
        $post->timestamp = isset($data['timestamp'])
            ? DateTimeHelper::toDateTime($data['timestamp'], false, false) ?: null
            : null;
        $post->likeCount = isset($data['likeCount']) ? (int) $data['likeCount'] : null;
        $post->commentsCount = isset($data['commentsCount']) ? (int) $data['commentsCount'] : null;
        $post->author = isset($data['author']) && is_array($data['author']) ? PostAuthor::fromArray($data['author']) : null;
        $post->images = array_map(
            fn(array $m) => PostMedia::fromArray($m),
            $data['images'] ?? [],
        );
        $post->videos = array_map(
            fn(array $m) => PostMedia::fromArray($m),
            $data['videos'] ?? [],
        );
        $post->children = array_map(
            fn(array $c) => self::fromArray($c),
            $data['children'] ?? [],
        );
        $post->meta = $data['meta'] ?? [];
        $post->raw = $data['raw'] ?? [];

        return $post;
    }
}
