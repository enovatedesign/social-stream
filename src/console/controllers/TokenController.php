<?php

namespace enovate\socialstream\console\controllers;

use craft\console\Controller;
use enovate\socialstream\jobs\RefreshTokenJob;
use enovate\socialstream\records\ConnectionRecord;
use yii\console\ExitCode;

/**
 * Manual token refresh command.
 *
 * The consolidated `social-stream/refresh` cron handles token refresh
 * automatically when a token is within 7 days of expiry. Use this command
 * to force an early refresh — for example after re-authenticating or
 * rotating the Instagram app secret.
 *
 * Usage:
 *   php craft social-stream/token/refresh             # Queue refresh for all sites
 *   php craft social-stream/token/refresh --site=1    # Queue refresh for a specific site
 */
class TokenController extends Controller
{
    /**
     * @var int|null Site ID to refresh. If null, refreshes all sites.
     */
    public ?int $site = null;

    public function options($actionID): array
    {
        $options = parent::options($actionID);
        $options[] = 'site';

        return $options;
    }

    /**
     * Queue an Instagram long-lived access token refresh for one or all sites.
     */
    public function actionRefresh(): int
    {
        $provider = 'instagram';

        if ($this->site !== null) {
            RefreshTokenJob::pushIfNotQueued($this->site, $provider);
            $this->stdout("Site {$this->site} ({$provider}): token refresh queued." . PHP_EOL);
            return ExitCode::OK;
        }

        $connections = ConnectionRecord::findAll(['provider' => $provider]);

        if (empty($connections)) {
            $this->stdout("No {$provider} connections found." . PHP_EOL);
            return ExitCode::OK;
        }

        foreach ($connections as $connection) {
            RefreshTokenJob::pushIfNotQueued($connection->siteId, $provider);
            $this->stdout("  Site {$connection->siteId} ({$provider}): token refresh queued." . PHP_EOL);
        }

        $this->stdout('Done. ' . count($connections) . ' job(s) queued.' . PHP_EOL);
        return ExitCode::OK;
    }
}
