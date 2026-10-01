<?php

namespace enovate\socialstream\migrations;

use craft\db\Migration;

/**
 * Adds the columns a YouTube connection needs now that it authenticates with an API
 * key rather than OAuth.
 *
 * - `apiKey` — the Google API key (encrypted), or the name of an environment
 *   variable holding it. It replaces the client ID and secret pair: `appId` and
 *   `appSecret` stay for Instagram, which still uses OAuth.
 * - `channelRef` — the channel URL, handle or ID exactly as the admin typed it. The
 *   resolved `UC…` ID goes in `providerUserId` and is what every call keys on, since
 *   a handle can be changed by its owner and reclaimed by someone else; this column
 *   exists so the field shows back what was entered rather than an ID the admin has
 *   never seen.
 *
 * `refreshToken` is left in place. It is unused by YouTube from here on and was
 * never used by Instagram, but dropping a column destroys whatever is in it, and an
 * unused nullable column costs nothing.
 */
class m260929_150000_add_youtube_api_key extends Migration
{
    /**
     * @var array<string, string> Column name => the column it should follow.
     */
    private const COLUMNS = [
        'apiKey' => 'appSecret',
        'channelRef' => 'providerUserId',
    ];

    public function safeUp(): bool
    {
        foreach (self::COLUMNS as $column => $after) {
            if ($this->db->columnExists('{{%socialstream_connections}}', $column)) {
                continue;
            }

            $definition = $column === 'apiKey' ? $this->text() : $this->string();

            $this->addColumn('{{%socialstream_connections}}', $column, $definition->after($after));
        }

        return true;
    }

    public function safeDown(): bool
    {
        foreach (array_keys(self::COLUMNS) as $column) {
            if ($this->db->columnExists('{{%socialstream_connections}}', $column)) {
                $this->dropColumn('{{%socialstream_connections}}', $column);
            }
        }

        return true;
    }
}
