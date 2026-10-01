<?php

namespace enovate\socialstream\auth;

/**
 * Encodes and decodes the OAuth `state` parameter.
 *
 * Both providers share one callback URL, so the state has to carry the provider
 * as well as the site. It is URL-safe base64 over JSON: Google and Instagram both
 * hand `state` back verbatim, and the URL-safe alphabet survives that round trip
 * without depending on either of them to escape `+` or `/` correctly.
 *
 * Deliberately not a security boundary. It is a routing hint, exactly as the
 * bare site ID it replaces was — never trust it for authorisation.
 */
class OAuthState
{
    public const DEFAULT_PROVIDER = 'instagram';

    public static function encode(int $siteId, string $provider): string
    {
        $json = json_encode([
            'siteId' => $siteId,
            'provider' => $provider,
        ]);

        return rtrim(strtr(base64_encode($json), '+/', '-_'), '=');
    }

    /**
     * Decode a state parameter, tolerating the bare-integer format this replaced.
     *
     * An Instagram OAuth redirect initiated before an upgrade comes back after it,
     * so the legacy format has to keep working — it is unambiguous, since the new
     * format is never all digits.
     *
     * @return array{siteId: int, provider: string}
     */
    public static function decode(?string $state): array
    {
        if ($state === null || $state === '') {
            return ['siteId' => 0, 'provider' => self::DEFAULT_PROVIDER];
        }

        if (ctype_digit($state)) {
            return ['siteId' => (int) $state, 'provider' => self::DEFAULT_PROVIDER];
        }

        $decoded = base64_decode(strtr($state, '-_', '+/'), true);
        $data = $decoded === false ? null : json_decode($decoded, true);

        if (!is_array($data)) {
            return ['siteId' => 0, 'provider' => self::DEFAULT_PROVIDER];
        }

        return [
            'siteId' => (int) ($data['siteId'] ?? 0),
            'provider' => self::normaliseProvider($data['provider'] ?? null),
        ];
    }

    /**
     * Provider handles reach a registry lookup, so anything not shaped like a
     * handle is discarded rather than passed along.
     */
    private static function normaliseProvider(mixed $provider): string
    {
        if (!is_string($provider) || !preg_match('/^[a-z0-9\-]{1,50}$/', $provider)) {
            return self::DEFAULT_PROVIDER;
        }

        return $provider;
    }
}
