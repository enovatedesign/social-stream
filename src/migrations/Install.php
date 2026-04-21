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
            'appId' => $this->text(),
            'appSecret' => $this->text(),
            'accessToken' => $this->text(),
            'providerUserId' => $this->string(),
            'tokenExpiresAt' => $this->dateTime(),
            'lastFetchAt' => $this->dateTime(),
            'lastError' => $this->text(),
            'lastErrorAt' => $this->dateTime(),
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
