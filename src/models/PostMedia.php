<?php

namespace enovate\socialstream\models;

use craft\base\Model;

/**
 * Normalised media attachment (image or video) on a Post.
 *
 * Provider-specific casing (e.g. Instagram's `'VIDEO'`, YouTube's `'youtube#video'`)
 * is mapped to the `TYPE_*` constants at the provider boundary.
 */
class PostMedia extends Model
{
    public const TYPE_IMAGE = 'image';
    public const TYPE_VIDEO = 'video';

    public ?string $type = null;

    public ?string $url = null;

    public ?string $thumbnailUrl = null;

    public ?int $width = null;

    public ?int $height = null;

    /**
     * @param array $fields Unused; present to match craft\base\Model signature.
     * @param array $expand Unused; present to match craft\base\Model signature.
     * @param bool $recursive Unused; present to match craft\base\Model signature.
     */
    public function toArray(array $fields = [], array $expand = [], bool $recursive = true): array
    {
        return [
            'type' => $this->type,
            'url' => $this->url,
            'thumbnailUrl' => $this->thumbnailUrl,
            'width' => $this->width,
            'height' => $this->height,
        ];
    }

    public static function fromArray(array $data): self
    {
        $media = new self();
        $media->type = $data['type'] ?? null;
        $media->url = $data['url'] ?? null;
        $media->thumbnailUrl = $data['thumbnailUrl'] ?? null;
        $media->width = isset($data['width']) ? (int) $data['width'] : null;
        $media->height = isset($data['height']) ? (int) $data['height'] : null;

        return $media;
    }
}
