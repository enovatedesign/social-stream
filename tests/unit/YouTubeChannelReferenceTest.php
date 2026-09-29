<?php

namespace enovate\socialstream\tests\unit;

use enovate\socialstream\providers\youtube\ChannelReference;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Tests the parser that turns whatever an admin pastes into a lookup the Data API
 * can answer.
 *
 * The input is "the thing in your address bar", which is four different URL shapes
 * plus two bare identifiers. Getting this wrong is worse than failing: a handle that
 * resolves to the wrong channel fills a site with a stranger's uploads, so anything
 * ambiguous must return null rather than a guess.
 */
class YouTubeChannelReferenceTest extends TestCase
{
    /**
     * @return array<string, array{0: string, 1: string}>
     */
    public static function channelIds(): array
    {
        return [
            'bare id' => ['UCuAXFkgsw1L7xaCfnd5JJOw', 'UCuAXFkgsw1L7xaCfnd5JJOw'],
            'channel url' => ['https://www.youtube.com/channel/UCuAXFkgsw1L7xaCfnd5JJOw', 'UCuAXFkgsw1L7xaCfnd5JJOw'],
            'no scheme' => ['youtube.com/channel/UCuAXFkgsw1L7xaCfnd5JJOw', 'UCuAXFkgsw1L7xaCfnd5JJOw'],
            'with trailing slash' => ['https://youtube.com/channel/UCuAXFkgsw1L7xaCfnd5JJOw/', 'UCuAXFkgsw1L7xaCfnd5JJOw'],
            'with a sub-page' => ['https://www.youtube.com/channel/UCuAXFkgsw1L7xaCfnd5JJOw/videos', 'UCuAXFkgsw1L7xaCfnd5JJOw'],
        ];
    }

    #[DataProvider('channelIds')]
    public function testChannelIdsResolveDirectly(string $input, string $expected): void
    {
        self::assertSame(
            ['type' => ChannelReference::TYPE_ID, 'value' => $expected],
            ChannelReference::parse($input),
        );
    }

    /**
     * @return array<string, array{0: string, 1: string}>
     */
    public static function handles(): array
    {
        return [
            'bare handle' => ['@EssexWebDevelopers', 'EssexWebDevelopers'],
            'handle url' => ['https://www.youtube.com/@EssexWebDevelopers', 'EssexWebDevelopers'],
            'no scheme' => ['youtube.com/@EssexWebDevelopers', 'EssexWebDevelopers'],
            'mobile host' => ['https://m.youtube.com/@EssexWebDevelopers', 'EssexWebDevelopers'],
            'with a sub-page' => ['https://www.youtube.com/@EssexWebDevelopers/shorts', 'EssexWebDevelopers'],
            'dots and dashes are legal' => ['@essex.web-devs', 'essex.web-devs'],
        ];
    }

    #[DataProvider('handles')]
    public function testHandlesAreParsedWithoutTheAtSign(string $input, string $expected): void
    {
        self::assertSame(
            ['type' => ChannelReference::TYPE_HANDLE, 'value' => $expected],
            ChannelReference::parse($input),
        );
    }

    public function testLegacyUsernameUrls(): void
    {
        self::assertSame(
            ['type' => ChannelReference::TYPE_USERNAME, 'value' => 'PHPUKConference'],
            ChannelReference::parse('https://www.youtube.com/user/PHPUKConference'),
        );
    }

    /**
     * The legacy `/c/` URL has no `channels.list` parameter at all. In practice the
     * auto-assigned handle usually matches the custom name, so it is tried as a
     * handle — the alternative is `search.list` at 100 quota units and fuzzy
     * matching, which can bind a site to the wrong channel.
     */
    public function testLegacyCustomUrlsAreTriedAsAHandle(): void
    {
        self::assertSame(
            ['type' => ChannelReference::TYPE_HANDLE, 'value' => 'EssexWebDevelopers'],
            ChannelReference::parse('https://www.youtube.com/c/EssexWebDevelopers'),
        );
    }

    /**
     * @return array<string, array{0: string, 1: string}>
     */
    public static function videoUrls(): array
    {
        return [
            'watch url' => ['https://www.youtube.com/watch?v=aqz-KE-bpKQ', 'aqz-KE-bpKQ'],
            'watch url with extra params' => ['https://www.youtube.com/watch?v=aqz-KE-bpKQ&t=42s', 'aqz-KE-bpKQ'],
            'short link' => ['https://youtu.be/aqz-KE-bpKQ', 'aqz-KE-bpKQ'],
            'shorts url' => ['https://www.youtube.com/shorts/aqz-KE-bpKQ', 'aqz-KE-bpKQ'],
            'embed url' => ['https://www.youtube.com/embed/aqz-KE-bpKQ', 'aqz-KE-bpKQ'],
            'live url' => ['https://www.youtube.com/live/aqz-KE-bpKQ', 'aqz-KE-bpKQ'],
        ];
    }

    /**
     * Any video from the channel identifies it, which saves an admin hunting for a
     * channel URL when a video link is what they have to hand.
     */
    #[DataProvider('videoUrls')]
    public function testVideoUrlsResolveViaTheirChannel(string $input, string $expected): void
    {
        self::assertSame(
            ['type' => ChannelReference::TYPE_VIDEO, 'value' => $expected],
            ChannelReference::parse($input),
        );
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function unusable(): array
    {
        return [
            'empty' => [''],
            'whitespace' => ["  \n "],
            'a bare word' => ['EssexWebDevelopers'],
            'another site' => ['https://vimeo.com/channels/staffpicks'],
            'a youtube url with no channel in it' => ['https://www.youtube.com/feed/subscriptions'],
            'a watch url with no id' => ['https://www.youtube.com/watch?t=42s'],
            'an @ on its own' => ['@'],
            'a handle with a space' => ['@essex web devs'],
            'a UC-prefixed string of the wrong length' => ['UCtooshort'],
        ];
    }

    /**
     * A bare word is the important one. It could be a handle, a legacy username or a
     * typo, and picking one would be a guess — the admin is asked for a URL instead.
     */
    #[DataProvider('unusable')]
    public function testUnusableInputReturnsNull(string $input): void
    {
        self::assertNull(ChannelReference::parse($input));
    }

    public function testSurroundingWhitespaceIsIgnored(): void
    {
        self::assertSame(
            ['type' => ChannelReference::TYPE_HANDLE, 'value' => 'EssexWebDevelopers'],
            ChannelReference::parse("  https://www.youtube.com/@EssexWebDevelopers  \n"),
        );
    }

    /**
     * `snippet.customUrl` is the channel's handle, but the API is inconsistent about
     * the `@` — older channels come back without it. The CP shows this next to an
     * Instagram username, so it has to read as a handle either way.
     */
    public function testHandlesAreFormattedWithALeadingAtSign(): void
    {
        self::assertSame('@foxesfarmfields', ChannelReference::formatHandle('foxesfarmfields'));
        self::assertSame('@foxesfarmfields', ChannelReference::formatHandle('@foxesfarmfields'));
        self::assertSame('@foxesfarmfields', ChannelReference::formatHandle('  @foxesfarmfields  '));
    }

    public function testAMissingHandleFormatsToNothing(): void
    {
        self::assertNull(ChannelReference::formatHandle(null));
        self::assertNull(ChannelReference::formatHandle(''));
        self::assertNull(ChannelReference::formatHandle('   '));
        self::assertNull(ChannelReference::formatHandle('@'));
    }

    /**
     * A channel ID is the only identifier that never changes, so it is what gets
     * stored — but the field has to show the admin something they recognise.
     */
    public function testCanonicalInputKeepsWhatWasTyped(): void
    {
        self::assertSame('@EssexWebDevelopers', ChannelReference::canonicalise('  @EssexWebDevelopers '));
        self::assertSame(
            'https://www.youtube.com/@EssexWebDevelopers',
            ChannelReference::canonicalise('https://www.youtube.com/@EssexWebDevelopers'),
        );
    }
}
