<?php

namespace enovate\socialstream\console\controllers;

use craft\console\Controller;
use craft\helpers\DateTimeHelper;
use DateTime;
use enovate\socialstream\providers\YouTubeProvider;
use enovate\socialstream\records\ConnectionRecord;
use enovate\socialstream\SocialStream;
use yii\console\ExitCode;

/**
 * Keeps YouTube's push notifications alive.
 *
 * A WebSub lease lasts at most 10 days, after which the hub stops delivering and new
 * uploads only appear when the cache expires or the refresh cron runs. Re-subscribing
 * resets the lease and is idempotent, so this is safe to run as often as you like —
 * connections whose lease is still comfortable are skipped unless --force is given.
 *
 * Usage:
 *   php craft social-stream/web-sub/renew                # every YouTube connection
 *   php craft social-stream/web-sub/renew --site=1        # one site
 *   php craft social-stream/web-sub/renew --within=5      # renew leases expiring within 5 days
 *   php craft social-stream/web-sub/renew --force         # renew regardless of lease
 *
 * Recommended cron: 0 3 * * * — a daily run renews only what needs it and leaves a
 * week of margin if a day is missed.
 */
class WebSubController extends Controller
{
    /**
     * @var int|null Site ID to renew. If null, every site with a YouTube connection.
     */
    public ?int $site = null;

    /**
     * @var int Renew when the lease expires within this many days.
     */
    public int $within = 3;

    /**
     * @var bool Renew every matched connection regardless of how much lease is left.
     */
    public bool $force = false;

    public function options($actionID): array
    {
        $options = parent::options($actionID);
        $options[] = 'site';
        $options[] = 'within';
        $options[] = 'force';

        return $options;
    }

    /**
     * Re-subscribe YouTube connections whose push notification lease is running out.
     */
    public function actionRenew(): int
    {
        $provider = SocialStream::$plugin->providers->getProviderByHandle(YouTubeProvider::handle());

        if (!$provider instanceof YouTubeProvider) {
            $this->stderr('The YouTube provider is not registered.' . PHP_EOL);

            return ExitCode::UNSPECIFIED_ERROR;
        }

        $query = ['provider' => YouTubeProvider::handle()];

        if ($this->site !== null) {
            $query['siteId'] = $this->site;
        }

        $connections = ConnectionRecord::findAll($query);

        if (empty($connections)) {
            $this->stdout('No YouTube connections found.' . PHP_EOL);

            return ExitCode::OK;
        }

        $renewed = 0;
        $failed = 0;

        foreach ($connections as $connection) {
            $siteId = (int) $connection->siteId;

            if (!$connection->accessToken) {
                $this->stdout("  Site {$siteId}: not connected — skipped." . PHP_EOL);
                continue;
            }

            if (!$this->needsRenewal($connection)) {
                $this->stdout(
                    "  Site {$siteId}: lease still valid until {$connection->websubExpiresAt} — skipped." . PHP_EOL
                );
                continue;
            }

            $result = $provider->subscribeWebSub($siteId);

            if ($result['success']) {
                $this->stdout("  Site {$siteId}: subscription renewed." . PHP_EOL);
                $renewed++;
            } else {
                $this->stderr("  Site {$siteId}: renewal failed — {$result['error']}" . PHP_EOL);
                $failed++;
            }
        }

        $this->stdout("Done. {$renewed} renewed, {$failed} failed." . PHP_EOL);

        return $failed > 0 ? ExitCode::UNSPECIFIED_ERROR : ExitCode::OK;
    }

    /**
     * A lease the hub has not confirmed yet reads as null, which is exactly the case
     * that needs subscribing.
     */
    private function needsRenewal(ConnectionRecord $connection): bool
    {
        if ($this->force || $connection->websubExpiresAt === null) {
            return true;
        }

        $expiresAt = DateTimeHelper::toDateTime($connection->websubExpiresAt);

        if ($expiresAt === false) {
            return true;
        }

        return $expiresAt <= (new DateTime())->modify('+' . max(0, $this->within) . ' days');
    }
}
