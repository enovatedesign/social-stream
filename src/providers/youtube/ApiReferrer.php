<?php

namespace enovate\socialstream\providers\youtube;

use Craft;
use craft\helpers\App;

/**
 * Works out the `Referer` to send with a YouTube Data API call.
 *
 * A key restricted to "Websites (HTTP referrers)" is checked against the header a
 * browser sets, and a server-side client sends none — so every call was rejected with
 * "Requests from referer <empty> are blocked", and a customer who had already
 * restricted their key that way had to change its restriction type to use the plugin
 * at all.
 *
 * The site's own base URL is the default because that is the domain such a key's
 * allowlist names. `apiReferrer` overrides it where the two differ: a canonical host,
 * or a production domain while a local `.test` site is tested against the same key.
 *
 * Nothing here is a security measure, and it does not pretend to be one — a header is
 * forgeable by anyone holding the key. It makes an existing restriction work. The
 * restriction that actually constrains a server-side key is on its IP address.
 */
class ApiReferrer
{
    /**
     * The referrer for a site's API calls, or null to send no header at all.
     *
     * Resolved per site, because connections are per site and so is the domain.
     */
    public function forSite(int $siteId): ?string
    {
        return self::normalise($this->configured(), $this->siteBaseUrl($siteId));
    }

    /**
     * Turn whatever was configured or stored into something that reads as a referrer.
     *
     * Google matches a referrer against patterns such as `example.com/*`, so a path is
     * kept and a trailing slash added. A value with no scheme is completed rather than
     * rejected — a bare domain is what someone copying an entry out of the Cloud
     * Console is most likely to paste — but a value with nothing except a scheme is
     * not a referrer: sending it would be rejected exactly as sending none was, while
     * hiding the reason.
     *
     * Static and free of Craft so the rule can be asserted on its own.
     */
    public static function normalise(?string $configured, ?string $siteBaseUrl): ?string
    {
        $value = trim((string) ($configured ?? ''));

        if ($value === '') {
            $value = trim((string) ($siteBaseUrl ?? ''));
        }

        if ($value === '') {
            return null;
        }

        [$scheme, $rest] = str_contains($value, '://')
            ? explode('://', $value, 2)
            : ['https', $value];

        $rest = ltrim($rest, '/');

        if ($rest === '') {
            return null;
        }

        return $scheme . '://' . rtrim($rest, '/') . '/';
    }

    /**
     * The configured override, with an environment variable name resolved.
     */
    private function configured(): ?string
    {
        $configured = Craft::$app->config->getConfigFromFile('social-stream')['apiReferrer'] ?? null;

        if (!is_string($configured) || trim($configured) === '') {
            return null;
        }

        return App::parseEnv($configured);
    }

    private function siteBaseUrl(int $siteId): ?string
    {
        $site = Craft::$app->sites->getSiteById($siteId) ?? Craft::$app->sites->getPrimarySite();
        $baseUrl = $site?->baseUrl;

        return $baseUrl !== null && $baseUrl !== '' ? App::parseEnv($baseUrl) : null;
    }
}
