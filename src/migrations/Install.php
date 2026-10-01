<?php

namespace enovate\socialstream\migrations;

use Craft;
use craft\db\Migration;

class Install extends Migration
{
    public function safeUp(): bool
    {
        $this->_createConnectionsTable();
        $this->_createSettingsTable();

        return true;
    }

    public function safeDown(): bool
    {
        $this->dropTableIfExists('{{%socialstream_settings}}');
        $this->dropTableIfExists('{{%socialstream_connections}}');

        return true;
    }

    private function _createConnectionsTable(): void
    {
        if ($this->db->tableExists('{{%socialstream_connections}}')) {
            return;
        }

        $this->createTable('{{%socialstream_connections}}', [
            'id' => $this->primaryKey(),
            'siteId' => $this->integer()->notNull(),
            'provider' => $this->string(50)->notNull()->defaultValue('instagram'),
            // OAuth app credentials, used by Instagram. YouTube authenticates with
            // an API key instead and leaves both null.
            'appId' => $this->text(),
            'appSecret' => $this->text(),
            'apiKey' => $this->text(),
            'accessToken' => $this->text(),
            // Unused. Google's refresh token lived here until YouTube moved to an API
            // key, and Instagram never had an equivalent — its long-lived access
            // token is the durable credential.
            'refreshToken' => $this->text(),
            // The account this connection reads: Instagram's user ID, or YouTube's
            // resolved `UC…` channel ID.
            'providerUserId' => $this->string(),
            // What the admin typed into YouTube's channel field, kept so the field can
            // show it back. `providerUserId` is what calls actually key on.
            'channelRef' => $this->string(),
            'tokenExpiresAt' => $this->dateTime(),
            'lastFetchAt' => $this->dateTime(),
            'lastError' => $this->text(),
            'lastErrorAt' => $this->dateTime(),
            'needsReauthAt' => $this->dateTime(),
            // YouTube push notifications: when the current WebSub lease runs out, the
            // secret the subscription was made with, and when one last arrived.
            'websubExpiresAt' => $this->dateTime(),
            'webhookSecret' => $this->text(),
            'webhookLastReceivedAt' => $this->dateTime(),
            'dateCreated' => $this->dateTime()->notNull(),
            'dateUpdated' => $this->dateTime()->notNull(),
            'uid' => $this->uid(),
        ]);

        $this->createIndex(
            null,
            '{{%socialstream_connections}}',
            ['siteId', 'provider'],
            true
        );

        $this->addForeignKey(
            null,
            '{{%socialstream_connections}}',
            'siteId',
            '{{%sites}}',
            'id',
            'CASCADE',
            'CASCADE'
        );
    }

    private function _createSettingsTable(): void
    {
        if ($this->db->tableExists('{{%socialstream_settings}}')) {
            return;
        }

        $this->createTable('{{%socialstream_settings}}', [
            'id' => $this->primaryKey(),
            'siteId' => $this->integer()->notNull(),
            'defaultLimit' => $this->integer()->defaultValue(25),
            'excludeNonFeed' => $this->boolean()->defaultValue(false),
            'cacheDuration' => $this->integer()->defaultValue(60),
            'secureApiEndpoint' => $this->boolean()->defaultValue(false),
            'apiToken' => $this->text(),
            'dateCreated' => $this->dateTime()->notNull(),
            'dateUpdated' => $this->dateTime()->notNull(),
            'uid' => $this->uid(),
        ]);

        $this->addForeignKey(
            null,
            '{{%socialstream_settings}}',
            'siteId',
            '{{%sites}}',
            'id',
            'CASCADE',
            'CASCADE'
        );
    }
}
