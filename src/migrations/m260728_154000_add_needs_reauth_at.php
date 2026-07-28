<?php

namespace enovate\socialstream\migrations;

use craft\db\Migration;

/**
 * Adds needsReauthAt to the connections table.
 *
 * Set when the provider rejects the stored credential outright (Instagram
 * OAuthException code 190), so the CP can report "the provider rejected this
 * token" rather than inferring expiry from a stored date that may itself be
 * stale. Cleared by any successful fetch, token refresh, or re-authorisation.
 */
class m260728_154000_add_needs_reauth_at extends Migration
{
    public function safeUp(): bool
    {
        if (!$this->db->columnExists('{{%socialstream_connections}}', 'needsReauthAt')) {
            $this->addColumn(
                '{{%socialstream_connections}}',
                'needsReauthAt',
                $this->dateTime()->after('lastErrorAt'),
            );
        }

        return true;
    }

    public function safeDown(): bool
    {
        if ($this->db->columnExists('{{%socialstream_connections}}', 'needsReauthAt')) {
            $this->dropColumn('{{%socialstream_connections}}', 'needsReauthAt');
        }

        return true;
    }
}
