<?php

namespace enovate\socialstream\models;

use craft\base\Model;

/**
 * Normalised author / account that published a Post.
 */
class PostAuthor extends Model
{
    public ?string $id = null;

    public ?string $name = null;

    public ?string $handle = null;

    public ?string $url = null;

    public ?string $avatarUrl = null;

    /**
     * @param array $fields Unused; present to match craft\base\Model signature.
     * @param array $expand Unused; present to match craft\base\Model signature.
     * @param bool $recursive Unused; present to match craft\base\Model signature.
     */
    public function toArray(array $fields = [], array $expand = [], bool $recursive = true): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'handle' => $this->handle,
            'url' => $this->url,
            'avatarUrl' => $this->avatarUrl,
        ];
    }

    public static function fromArray(array $data): self
    {
        $author = new self();
        $author->id = $data['id'] ?? null;
        $author->name = $data['name'] ?? null;
        $author->handle = $data['handle'] ?? null;
        $author->url = $data['url'] ?? null;
        $author->avatarUrl = $data['avatarUrl'] ?? null;

        return $author;
    }
}
