<?php

namespace enovate\socialstream\console\controllers;

use craft\console\Controller;
use enovate\socialstream\jobs\RefreshStreamJob;
use enovate\socialstream\records\ConnectionRecord;
use enovate\socialstream\SocialStream;
use yii\console\ExitCode;

/**
 * Stream cache refresh commands.
 *
 * Usage:
 *   php craft social-stream/refresh                         # Refresh all connected sites for all providers
 *   php craft social-stream/refresh --site=1                # Refresh a specific site (all providers)
 *   php craft social-stream/refresh --provider=instagram    # Refresh one provider across all sites
 *   php craft social-stream/refresh --site=1 --provider=instagram
 */
class RefreshController extends Controller
{
    /**
     * @var int|null Site ID to refresh. If null, refreshes all sites.
     */
    public ?int $site = null;

    /**
     * @var string|null Provider handle to refresh. If null, refreshes all registered providers.
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
     * Push a RefreshStreamJob to the queue for one or all sites / providers.
     */
    public function actionIndex(): int
    {
        $providerHandles = $this->provider !== null
            ? [$this->provider]
            : array_keys(SocialStream::$plugin->providers->getAllProviders());

        if (empty($providerHandles)) {
            $this->stdout("No providers registered." . PHP_EOL);
            return ExitCode::OK;
        }

        $queued = 0;

        foreach ($providerHandles as $handle) {
            $query = ['provider' => $handle];
            if ($this->site !== null) {
                $query['siteId'] = $this->site;
            }

            $connections = ConnectionRecord::findAll($query);

            if (empty($connections)) {
                $this->stdout("No {$handle} connections found." . PHP_EOL);
                continue;
            }

            foreach ($connections as $connection) {
                RefreshStreamJob::pushIfNotQueued($connection->siteId, [], $handle);
                $this->stdout("  Site {$connection->siteId} ({$handle}): queued" . PHP_EOL);
                $queued++;
            }
        }

        $this->stdout("Done. {$queued} job(s) queued." . PHP_EOL);
        return ExitCode::OK;
    }
}
