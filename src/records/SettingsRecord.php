<?php

namespace enovate\socialstream\records;

use craft\db\ActiveRecord;

/**
 * ActiveRecord for the socialstream_settings table.
 *
 * @property int $id
 * @property int $siteId
 * @property int $defaultLimit
 * @property bool $excludeNonFeed
 * @property int $cacheDuration
 * @property bool $secureApiEndpoint
 * @property string|null $apiToken
 */
class SettingsRecord extends ActiveRecord
{
    public static function tableName(): string
    {
        return '{{%socialstream_settings}}';
    }
}
