<?php

namespace enovate\socialstream\migrations;

use craft\db\Migration;

/**
 * Adds the columns YouTube connections need to the connections table.
 *
 * - `refreshToken` — Google's permanent-ish refresh token (encrypted). Instagram
 *   has no equivalent: its long-lived access token *is* the durable credential,
 *   so the column stays null on Instagram rows.
 * - `websubExpiresAt` — when the current WebSub (PubSubHubbub) lease runs out.
 *   Leases cap at 10 days, so this is what the renewal job and the CP read to
 *   decide whether push notifications are still live.
 * - `webhookSecret` — the `hub.secret` handed to the hub at subscribe time
 *   (encrypted), used to verify the HMAC-SHA1 on each notification.
 * - `webhookLastReceivedAt` — when a notification last arrived, so the CP can
 *   distinguish "subscribed" from "subscribed and actually delivering".
 *
 * The YouTube channel ID reuses `providerUserId` — same semantics as Instagram's
 * user ID, so no new column.
 */
class m260928_120000_add_youtube_fields extends Migration
{
    /**
     * @var array<string, string> Column name => the column it should follow.
     */
    private const COLUMNS = [
        'refreshToken' => 'accessToken',
        'websubExpiresAt' => 'needsReauthAt',
        'webhookSecret' => 'websubExpiresAt',
        'webhookLastReceivedAt' => 'webhookSecret',
    ];

    public function safeUp(): bool
    {
        foreach (self::COLUMNS as $column => $after) {
            if ($this->db->columnExists('{{%socialstream_connections}}', $column)) {
                continue;
            }

            $definition = $column === 'refreshToken' || $column === 'webhookSecret'
                ? $this->text()
                : $this->dateTime();

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
