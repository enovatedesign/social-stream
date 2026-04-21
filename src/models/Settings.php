<?php

namespace enovate\socialstream\models;

use craft\base\Model;

class Settings extends Model
{
    public ?string $appId = null;

    public ?string $appSecret = null;

    public int $defaultLimit = 25;

    public bool $excludeNonFeed = false;

    public int $cacheDuration = 60;

    public bool $secureApiEndpoint = false;

    public ?string $apiToken = null;

    public function rules(): array
    {
        return [
            [['defaultLimit', 'cacheDuration'], 'required'],
            ['defaultLimit', 'integer', 'min' => 1, 'max' => 100],
            ['cacheDuration', 'integer', 'min' => 1],
            [['excludeNonFeed', 'secureApiEndpoint'], 'boolean'],
            [['appId', 'appSecret', 'apiToken'], 'string'],
        ];
    }
}
