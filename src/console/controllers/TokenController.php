<?php

namespace enovate\socialstream\console\controllers;

use Craft;
use craft\console\Controller;
use enovate\socialstream\SocialStream;
use yii\console\ExitCode;

/**
 * Token management commands.
 *
 * Usage:
 *   php craft social-stream/token/refresh          # Refresh all sites
 *   php craft social-stream/token/refresh --site=1  # Refresh a specific site
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
     * Refresh Instagram long-lived access token(s).
     */
    public function actionRefresh(): int
    {
        if ($this->site !== null) {
            $this->stdout("Refreshing token for site {$this->site}..." . PHP_EOL);
            $result = SocialStream::$plugin->token->refreshToken($this->site, 'instagram');

            if ($result['success']) {
                $this->stdout("Token refreshed successfully." . PHP_EOL);
                return ExitCode::OK;
            }

            $this->stderr("Failed: {$result['error']}" . PHP_EOL);
            return ExitCode::UNSPECIFIED_ERROR;
        }

        $this->stdout("Refreshing tokens for all sites..." . PHP_EOL);
        $result = SocialStream::$plugin->token->refreshAllTokens('instagram');

        foreach ($result['results'] as $siteId => $siteResult) {
            if ($siteResult['success']) {
                $this->stdout("  Site {$siteId}: OK" . PHP_EOL);
            } else {
                $this->stderr("  Site {$siteId}: FAILED — {$siteResult['error']}" . PHP_EOL);
            }
        }

        return $result['success'] ? ExitCode::OK : ExitCode::UNSPECIFIED_ERROR;
    }
}
