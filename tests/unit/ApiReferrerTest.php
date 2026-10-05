<?php

namespace enovate\socialstream\tests\unit;

use enovate\socialstream\providers\youtube\ApiReferrer;
use PHPUnit\Framework\TestCase;

/**
 * Tests the Referer sent with a Data API call.
 *
 * A key restricted to "Websites (HTTP referrers)" checks the `Referer` header, which
 * a browser sets and a server-side HTTP client does not — so the Data API rejected
 * every call from this plugin with "Requests from referer <empty> are blocked". The
 * site's own address is what a customer has in that key's allowlist, so it is what
 * gets sent, and the restriction works without anyone having to change its type.
 *
 * Sending it is harmless to the other restriction modes: an IP-restricted key checks
 * the source address and an unrestricted one checks nothing, and neither looks at
 * this header.
 */
class ApiReferrerTest extends TestCase
{
    private function normalise(?string $configured, ?string $siteBaseUrl): ?string
    {
        return ApiReferrer::normalise($configured, $siteBaseUrl);
    }

    public function testTheSiteAddressIsSentWhenNothingIsConfigured(): void
    {
        self::assertSame('https://example.com/', $this->normalise(null, 'https://example.com'));
    }

    /**
     * The allowlist may name a domain the site's own base URL doesn't match — a
     * canonical host, or the production domain while testing locally.
     */
    public function testAConfiguredReferrerWinsOverTheSiteAddress(): void
    {
        self::assertSame(
            'https://canonical.example/',
            $this->normalise('https://canonical.example', 'https://foxes-farm-fields.test')
        );
    }

    public function testATrailingSlashIsNeitherDoubledNorMissing(): void
    {
        self::assertSame('https://example.com/', $this->normalise(null, 'https://example.com/'));
        self::assertSame('https://example.com/', $this->normalise(null, 'https://example.com'));
    }

    /**
     * Google matches a referrer against patterns like `example.com/*`, so a path is
     * kept — a multi-site install may serve a site from a subdirectory.
     */
    public function testAPathIsPreserved(): void
    {
        self::assertSame('https://example.com/uk/', $this->normalise(null, 'https://example.com/uk'));
    }

    /**
     * A bare domain is what someone copying an entry out of the Cloud Console is
     * most likely to paste, and a Referer without a scheme is not a URL.
     */
    public function testABareDomainGetsAScheme(): void
    {
        self::assertSame('https://example.com/', $this->normalise('example.com', null));
    }

    public function testAProtocolRelativeAddressGetsASchemeWithoutDoublingSlashes(): void
    {
        self::assertSame('https://example.com/', $this->normalise('//example.com', null));
    }

    /**
     * Not every site is HTTPS — a staging domain on plain HTTP has to be sent as the
     * allowlist has it, not silently upgraded.
     */
    public function testAnExplicitSchemeIsLeftAlone(): void
    {
        self::assertSame('http://staging.example/', $this->normalise('http://staging.example', null));
    }

    public function testSurroundingWhitespaceIsIgnored(): void
    {
        self::assertSame('https://example.com/', $this->normalise('  https://example.com  ', null));
        self::assertSame('https://example.com/', $this->normalise('   ', 'https://example.com'));
    }

    /**
     * With no address to send, no header is sent — an empty or invented Referer would
     * be rejected exactly as sending none was, and would hide the real cause.
     */
    public function testNoAddressMeansNoHeader(): void
    {
        self::assertNull($this->normalise(null, null));
        self::assertNull($this->normalise('', ''));
        self::assertNull($this->normalise('  ', null));
    }

    /**
     * A console run has no request behind it, and a site may legitimately have no
     * base URL — neither is a reason to send something meaningless.
     */
    public function testAnAddressThatIsOnlyASchemeIsNotSent(): void
    {
        self::assertNull($this->normalise('https://', null));
        self::assertNull($this->normalise('//', null));
    }
}
