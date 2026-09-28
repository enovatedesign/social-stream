<?php

namespace enovate\socialstream\console\controllers;

use craft\console\Controller;
use enovate\socialstream\jobs\RefreshTokenJob;
use enovate\socialstream\records\ConnectionRecord;
use enovate\socialstream\SocialStream;
use yii\console\ExitCode;

/**
 * Manual token refresh command.
 *
 * The consolidated `social-stream/refresh` cron handles token refresh
 * automatically when a token is within 7 days of expiry. Use this command
 * to force an early refresh — for example after re-authenticating or
 * rotating an app secret.
 *
 * Usage:
 *   php craft social-stream/token/refresh                      # every provider, every site
 *   php craft social-stream/token/refresh --site=1              # a specific site
 *   php craft social-stream/token/refresh --provider=instagram  # a specific provider
 */
class TokenController extends Controller
{
    /**
     * @var int|null Site ID to refresh. If null, refreshes all sites.
     */
    public ?int $site = null;

    /**
     * @var string|null Provider handle to refresh. If null, every registered provider.
     */
    public ?string $provider = null;

    public function options($actionID): array
    {
        $options = parent::options($actionID);
        $options[] = 'site';
        $options[] = 'provider';

        return $options;
    }

    /**
     * Queue an access token refresh for one or all sites.
     */
    public function actionRefresh(): int
    {
        $providerHandles = $this->provider !== null
            ? [$this->provider]
            : array_keys(SocialStream::$plugin->providers->getAllProviders());

        if (empty($providerHandles)) {
            $this->stdout('No providers registered.' . PHP_EOL);

            return ExitCode::OK;
        }

        $queued = 0;

        foreach ($providerHandles as $provider) {
            if ($this->site !== null) {
                $queued += $this->queue($this->site, $provider) ? 1 : 0;
                continue;
            }

            $connections = ConnectionRecord::findAll(['provider' => $provider]);

            if (empty($connections)) {
                $this->stdout("No {$provider} connections found." . PHP_EOL);
                continue;
            }

            foreach ($connections as $connection) {
                $queued += $this->queue((int) $connection->siteId, $provider) ? 1 : 0;
            }
        }

        $this->stdout("Done. {$queued} job(s) queued." . PHP_EOL);

        return ExitCode::OK;
    }

    private function queue(int $siteId, string $provider): bool
    {
        $pushed = RefreshTokenJob::pushIfNotQueued($siteId, $provider);

        $this->stdout($pushed
            ? "  Site {$siteId} ({$provider}): token refresh queued." . PHP_EOL
            : "  Site {$siteId} ({$provider}): token refresh already queued." . PHP_EOL);

        return $pushed;
    }
}
