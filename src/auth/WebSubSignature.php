<?php

namespace enovate\socialstream\auth;

/**
 * The HMAC on a WebSub notification.
 *
 * SHA-1, not SHA-256 — that is what the WebSub specification settled on and what
 * YouTube's hub sends, and it is the one detail most likely to be copied wrongly
 * from the Instagram webhook next door.
 */
class WebSubSignature
{
    public const ALGORITHM = 'sha1';

    /**
     * Whether the header matches an HMAC of the body under this secret.
     *
     * A missing secret or header fails: a subscription made with `hub.secret` always
     * gets a signed notification back, so an unsigned one cannot be shown to have
     * come from the hub.
     */
    public static function matches(?string $secret, ?string $signature, string $body): bool
    {
        if ($secret === null || $secret === '' || $signature === null || $signature === '') {
            return false;
        }

        return hash_equals(self::sign($secret, $body), $signature);
    }

    /**
     * The header value the hub is expected to send: `sha1={hex digest}`.
     */
    public static function sign(string $secret, string $body): string
    {
        return self::ALGORITHM . '=' . hash_hmac(self::ALGORITHM, $body, $secret);
    }
}
