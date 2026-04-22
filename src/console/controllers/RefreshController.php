<?php

namespace enovate\socialstream\console\controllers;

use craft\console\Controller;
use craft\helpers\DateTimeHelper;
use DateTime;
use enovate\socialstream\jobs\RefreshStreamJob;
use enovate\socialstream\jobs\RefreshTokenJob;
use enovate\socialstream\records\ConnectionRecord;
use enovate\socialstream\services\TokenService;
use enovate\socialstream\SocialStream;
use yii\console\ExitCode;

/**
 * Consolidated cron entry point for Social Stream.
 *
 * Each invocation:
 *   1. Pre-warms the stream cache by queueing a RefreshStreamJob per connection.
 *   2. Checks each connection's token expiry and queues a RefreshTokenJob when
 *      a token is within TokenService::REFRESH_THRESHOLD_DAYS of expiry.
 *
 * Safe to run on every web host — both jobs dedupe via the Craft queue table
 * (primary DB read) before pushing.
 *
 * Usage:
 *   php craft social-stream/refresh                             # all sites, all providers
 *   php craft social-stream/refresh --site=1                    # single site
 *   php craft social-stream/refresh --provider=instagram        # single provider
 *   php craft social-stream/refresh --force-token               # queue token refresh regardless of expiry
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

    /**
     * @var bool Queue a token refresh for every matched connection regardless of expiry.
     */
    public bool $forceToken = false;

    public function options($actionID): array
    {
        $options = parent::options($actionID);
        $options[] = 'site';
        $options[] = 'provider';
        $options[] = 'forceToken';

        return $options;
    }

    public function optionAliases(): array
    {
        return array_merge(parent::optionAliases(), [
            'force-token' => 'forceToken',
        ]);
    }

    /**
     * Run the consolidated cron: stream pre-warm + opportunistic token refresh.
     */
    public function actionIndex(): int
    {
        $providerHandles = $this->provider !== null
            ? [$this->provider]
            : array_keys(SocialStream::$plugin->providers->getAllProviders());

        if (empty($providerHandles)) {
            $this->stdout('No providers registered.' . PHP_EOL);
            return ExitCode::OK;
        }

        $streamsQueued = 0;
        $tokensQueued = 0;

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
                $this->stdout("  Site {$connection->siteId} ({$handle}): stream refresh queued" . PHP_EOL);
                $streamsQueued++;

                if ($this->shouldRefreshToken($connection)) {
                    RefreshTokenJob::pushIfNotQueued($connection->siteId, $handle);
                    $expiry = $this->formatExpiry($connection->tokenExpiresAt);
                    $this->stdout("  Site {$connection->siteId} ({$handle}): token refresh queued ({$expiry})" . PHP_EOL);
                    $tokensQueued++;
                }
            }
        }

        $this->stdout("Done. {$streamsQueued} stream job(s), {$tokensQueued} token job(s) queued." . PHP_EOL);
        return ExitCode::OK;
    }

    private function shouldRefreshToken(ConnectionRecord $connection): bool
    {
        if ($this->forceToken) {
            return true;
        }

        if ($connection->tokenExpiresAt === null) {
            return false;
        }

        $expiresAt = DateTimeHelper::toDateTime($connection->tokenExpiresAt);
        if ($expiresAt === false) {
            return false;
        }

        $threshold = (new DateTime())->modify('+' . TokenService::REFRESH_THRESHOLD_DAYS . ' days');

        return $expiresAt < $threshold;
    }

    private function formatExpiry(?string $tokenExpiresAt): string
    {
        if ($tokenExpiresAt === null) {
            return 'forced';
        }

        $expiresAt = DateTimeHelper::toDateTime($tokenExpiresAt);
        return $expiresAt === false
            ? 'unknown expiry'
            : 'expires ' . $expiresAt->format('Y-m-d');
    }
}
