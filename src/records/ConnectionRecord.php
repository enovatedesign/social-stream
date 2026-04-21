<?php

namespace enovate\socialstream\records;

use craft\db\ActiveRecord;

/**
 * ActiveRecord for the socialstream_connections table.
 *
 * @property int $id
 * @property int $siteId
 * @property string $provider
 * @property string|null $appId
 * @property string|null $appSecret
 * @property string|null $accessToken
 * @property string|null $providerUserId
 * @property string|null $tokenExpiresAt
 * @property string|null $lastFetchAt
 * @property string|null $lastError
 * @property string|null $lastErrorAt
 */
class ConnectionRecord extends ActiveRecord
{
    public static function tableName(): string
    {
        return '{{%socialstream_connections}}';
    }
}
