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
 * @property string|null $needsReauthAt
 */
class ConnectionRecord extends ActiveRecord
{
    public static function tableName(): string
    {
        return '{{%socialstream_connections}}';
    }

    /**
     * Between `composer update` and `php craft up`, the 1.3.0 column doesn't exist
     * yet — and Yii throws UnknownPropertyException for an attribute that isn't in
     * the table. Front-end requests and cron runs both read this on hot paths, so
     * these accessors degrade to "not flagged" for that window rather than taking
     * the site's stream down with it.
     *
     * Once the migration has run the column is a real attribute and Yii's
     * __get()/__set() never reach these methods.
     */
    public function getNeedsReauthAt(): ?string
    {
        return $this->hasAttribute('needsReauthAt') ? $this->needsReauthAt : null;
    }

    public function setNeedsReauthAt(?string $value): void
    {
        if ($this->hasAttribute('needsReauthAt')) {
            $this->needsReauthAt = $value;
        }
    }
}
