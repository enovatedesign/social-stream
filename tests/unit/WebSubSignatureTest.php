<?php

namespace enovate\socialstream\tests\unit;

use enovate\socialstream\auth\WebSubSignature;
use PHPUnit\Framework\TestCase;

/**
 * Tests the WebSub notification signature.
 *
 * SHA-1, not the SHA-256 the Instagram webhook next door would use — that is what
 * the WebSub specification settled on, and getting it wrong would reject every
 * genuine notification (or, with the check skipped, accept forged ones).
 */
class WebSubSignatureTest extends TestCase
{
    private const SECRET = 'a-shared-secret';

    private const BODY = '<feed><entry><yt:videoId>abc</yt:videoId></entry></feed>';

    public function testSignsWithSha1(): void
    {
        self::assertSame(
            'sha1=' . hash_hmac('sha1', self::BODY, self::SECRET),
            WebSubSignature::sign(self::SECRET, self::BODY)
        );
    }

    public function testAcceptsItsOwnSignature(): void
    {
        $signature = WebSubSignature::sign(self::SECRET, self::BODY);

        self::assertTrue(WebSubSignature::matches(self::SECRET, $signature, self::BODY));
    }

    public function testRejectsASha256Signature(): void
    {
        self::assertFalse(
            WebSubSignature::matches(
                self::SECRET,
                'sha256=' . hash_hmac('sha256', self::BODY, self::SECRET),
                self::BODY
            )
        );
    }

    public function testRejectsATamperedBody(): void
    {
        $signature = WebSubSignature::sign(self::SECRET, self::BODY);

        self::assertFalse(WebSubSignature::matches(self::SECRET, $signature, self::BODY . ' '));
    }

    public function testRejectsAnotherSecretsSignature(): void
    {
        $signature = WebSubSignature::sign('a-different-secret', self::BODY);

        self::assertFalse(WebSubSignature::matches(self::SECRET, $signature, self::BODY));
    }

    public function testRejectsAnUnsignedNotification(): void
    {
        self::assertFalse(WebSubSignature::matches(self::SECRET, null, self::BODY));
        self::assertFalse(WebSubSignature::matches(self::SECRET, '', self::BODY));
    }

    public function testRejectsEverythingWhenNoSecretIsStored(): void
    {
        self::assertFalse(
            WebSubSignature::matches(null, WebSubSignature::sign(self::SECRET, self::BODY), self::BODY),
            'A connection with no stored secret cannot authenticate anything.'
        );
    }

    public function testMissingDigestDoesNotMatchTheBareAlgorithm(): void
    {
        self::assertFalse(WebSubSignature::matches(self::SECRET, 'sha1=', self::BODY));
    }
}
