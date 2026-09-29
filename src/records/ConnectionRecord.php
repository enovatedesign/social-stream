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
 * @property string|null $apiKey
 * @property string|null $accessToken
 * @property string|null $refreshToken
 * @property string|null $providerUserId
 * @property string|null $channelRef
 * @property string|null $tokenExpiresAt
 * @property string|null $lastFetchAt
 * @property string|null $lastError
 * @property string|null $lastErrorAt
 * @property string|null $needsReauthAt
 * @property string|null $websubExpiresAt
 * @property string|null $webhookSecret
 * @property string|null $webhookLastReceivedAt
 */
class ConnectionRecord extends ActiveRecord
{
    public static function tableName(): string
    {
        return '{{%socialstream_connections}}';
    }

    /**
     * Between `composer update` and `php craft up`, a newly added column doesn't
     * exist yet — and Yii throws UnknownPropertyException for an attribute that
     * isn't in the table. Front-end requests, cron runs and the anonymous webhook
     * endpoint all read these on hot paths, so the accessors below degrade to
     * "not set" for that window rather than taking the site's stream down with it.
     *
     * Once the migration has run each column is a real attribute and Yii's
     * __get()/__set() never reach these methods.
     */
    public function getNeedsReauthAt(): ?string
    {
        return $this->readIfPresent('needsReauthAt');
    }

    public function setNeedsReauthAt(?string $value): void
    {
        $this->writeIfPresent('needsReauthAt', $value);
    }

    public function getRefreshToken(): ?string
    {
        return $this->readIfPresent('refreshToken');
    }

    public function setRefreshToken(?string $value): void
    {
        $this->writeIfPresent('refreshToken', $value);
    }

    public function getApiKey(): ?string
    {
        return $this->readIfPresent('apiKey');
    }

    public function setApiKey(?string $value): void
    {
        $this->writeIfPresent('apiKey', $value);
    }

    public function getChannelRef(): ?string
    {
        return $this->readIfPresent('channelRef');
    }

    public function setChannelRef(?string $value): void
    {
        $this->writeIfPresent('channelRef', $value);
    }

    public function getWebsubExpiresAt(): ?string
    {
        return $this->readIfPresent('websubExpiresAt');
    }

    public function setWebsubExpiresAt(?string $value): void
    {
        $this->writeIfPresent('websubExpiresAt', $value);
    }

    public function getWebhookSecret(): ?string
    {
        return $this->readIfPresent('webhookSecret');
    }

    public function setWebhookSecret(?string $value): void
    {
        $this->writeIfPresent('webhookSecret', $value);
    }

    public function getWebhookLastReceivedAt(): ?string
    {
        return $this->readIfPresent('webhookLastReceivedAt');
    }

    public function setWebhookLastReceivedAt(?string $value): void
    {
        $this->writeIfPresent('webhookLastReceivedAt', $value);
    }

    private function readIfPresent(string $attribute): ?string
    {
        return $this->hasAttribute($attribute) ? $this->{$attribute} : null;
    }

    private function writeIfPresent(string $attribute, ?string $value): void
    {
        if ($this->hasAttribute($attribute)) {
            $this->{$attribute} = $value;
        }
    }
}
