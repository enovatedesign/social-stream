<?php

namespace enovate\socialstream\tests\unit;

use enovate\socialstream\auth\OAuthState;
use PHPUnit\Framework\TestCase;

/**
 * Tests the OAuth `state` codec.
 *
 * Every provider shares one callback URL, so the state is what tells the callback
 * which flow came back. The case worth guarding is the upgrade window: an Instagram
 * authorisation started before this release comes back after it, carrying a bare site
 * ID, and it must still land on the Instagram flow.
 */
class OAuthStateTest extends TestCase
{
    public function testRoundTripsSiteAndProvider(): void
    {
        $decoded = OAuthState::decode(OAuthState::encode(3, 'youtube'));

        self::assertSame(3, $decoded['siteId']);
        self::assertSame('youtube', $decoded['provider']);
    }

    public function testEncodesUrlSafely(): void
    {
        $state = OAuthState::encode(1234567, 'instagram');

        self::assertSame(
            $state,
            rawurlencode($state),
            'The state travels in a query string and comes back verbatim, so it must need no escaping.'
        );
    }

    public function testALegacyBareSiteIdIsReadAsInstagram(): void
    {
        $decoded = OAuthState::decode('7');

        self::assertSame(7, $decoded['siteId']);
        self::assertSame(
            'instagram',
            $decoded['provider'],
            'The bare-integer format predates multi-provider support, so it can only be Instagram.'
        );
    }

    public function testTheNewFormatIsNeverMistakenForTheLegacyOne(): void
    {
        self::assertFalse(
            ctype_digit(OAuthState::encode(1, 'youtube')),
            'An all-digit encoding would be indistinguishable from a legacy site ID.'
        );
    }

    public function testGarbageDecodesToNoSiteAndTheDefaultProvider(): void
    {
        $decoded = OAuthState::decode('not-base64-json');

        self::assertSame(0, $decoded['siteId']);
        self::assertSame('instagram', $decoded['provider']);
    }

    public function testAMissingStateDecodesToNoSite(): void
    {
        foreach ([null, ''] as $state) {
            $decoded = OAuthState::decode($state);

            self::assertSame(0, $decoded['siteId']);
            self::assertSame('instagram', $decoded['provider']);
        }
    }

    public function testAProviderThatIsNotAHandleIsDiscarded(): void
    {
        $state = rtrim(strtr(base64_encode(json_encode([
            'siteId' => 2,
            'provider' => '../../etc/passwd',
        ])), '+/', '-_'), '=');

        $decoded = OAuthState::decode($state);

        self::assertSame(2, $decoded['siteId']);
        self::assertSame(
            'instagram',
            $decoded['provider'],
            'The handle reaches a registry lookup, so anything not shaped like one is dropped.'
        );
    }

    public function testASiteIdArrivingAsAStringIsCoerced(): void
    {
        $state = rtrim(strtr(base64_encode(json_encode([
            'siteId' => '5',
            'provider' => 'youtube',
        ])), '+/', '-_'), '=');

        self::assertSame(5, OAuthState::decode($state)['siteId']);
    }
}
