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
 *      a token is within TokenService::REFRESH_THRESHOLD_DAYS of expiry. Providers
 *      that don't use OAuth at all (YouTube) are skipped, as are connections
 *      that renew their access token inline from a refresh token, which are
 *      skipped — step 1 already exercises that path every run.
 *
 * Safe to run on every web host — both jobs hold a DB-backed lock across the queue-table
 * check and the push, so simultaneous hosts produce one job, not one each.
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
            // A provider without OAuth has no credential that expires, so the whole
            // token half of this run is skipped for it — the stream pre-warm above
            // is all it needs.
            $registered = SocialStream::$plugin->providers->getProviderByHandle($handle);
            $usesOAuth = $registered === null || $registered::usesOAuth();

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
                if (RefreshStreamJob::pushIfNotQueued($connection->siteId, [], $handle)) {
                    $this->stdout("  Site {$connection->siteId} ({$handle}): stream refresh queued" . PHP_EOL);
                    $streamsQueued++;
                } else {
                    $this->stdout("  Site {$connection->siteId} ({$handle}): stream refresh already queued" . PHP_EOL);
                }

                if ($usesOAuth && $this->shouldRefreshToken($connection)) {
                    $expiry = $this->formatExpiry($connection->tokenExpiresAt);

                    if (RefreshTokenJob::pushIfNotQueued($connection->siteId, $handle)) {
                        $this->stdout("  Site {$connection->siteId} ({$handle}): token refresh queued ({$expiry})" . PHP_EOL);
                        $tokensQueued++;
                    } else {
                        $this->stdout("  Site {$connection->siteId} ({$handle}): token refresh already queued ({$expiry})" . PHP_EOL);
                    }
                } elseif ($connection->needsReauthAt !== null) {
                    $this->stdout(
                        "  Site {$connection->siteId} ({$handle}): token rejected by provider — "
                        . 're-authorise in the control panel' . PHP_EOL
                    );
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

        // The provider has already rejected this credential; refreshing it can only
        // fail the same way. Re-authorisation is the only route back.
        if ($connection->needsReauthAt !== null) {
            return false;
        }

        // A connection holding a refresh token renews its access token inline, on
        // demand. YouTube's lasts an hour, so the expiry threshold below would queue
        // a job on every single run — and it would learn nothing: the stream
        // pre-warm queued above goes through the same inline refresh, so a dead
        // refresh token is already surfaced once per run without the extra job.
        if ($connection->refreshToken) {
            return false;
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
