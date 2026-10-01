<?php

namespace enovate\socialstream\tests\unit;

use enovate\socialstream\base\ProviderInterface;
use enovate\socialstream\providers\InstagramProvider;
use enovate\socialstream\providers\YouTubeProvider;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

/**
 * Tests what a registered provider is required to answer.
 *
 * Two bugs came out of these questions being asked of providers that were not
 * obliged to answer them. The control panel, the auth flow, the token service and
 * both console commands call `usesOAuth()` statically on whatever the registry hands
 * back — so while it lived only on the abstract base class, a provider registered by
 * another plugin took the settings page down with a fatal error rather than being
 * skipped. And because YouTube answers `false`, nothing may infer a YouTube
 * connection's health from the OAuth token columns: that is how the WebSub renewal
 * command came to skip every connection it was written to renew.
 */
class ProviderContractTest extends TestCase
{
    /**
     * @return array<string, array{0: string}>
     */
    public static function contractMethods(): array
    {
        return [
            'usesOAuth' => ['usesOAuth'],
            'usesExcludeNonFeed' => ['usesExcludeNonFeed'],
        ];
    }

    #[DataProvider('contractMethods')]
    public function testTheContractRequiresTheQuestionToBeAnswerable(string $method): void
    {
        $interface = new ReflectionClass(ProviderInterface::class);

        self::assertTrue(
            $interface->hasMethod($method),
            "Every registered provider must answer {$method}(): callers invoke it on whatever "
            . 'the registry returns, and a provider that cannot answer is a fatal error.'
        );

        $declared = $interface->getMethod($method);

        self::assertTrue($declared->isStatic(), "{$method}() is called statically.");
        self::assertTrue($declared->isPublic(), "{$method}() is called from outside the provider.");
    }

    /**
     * The question the WebSub renewal command got wrong: YouTube has no access token,
     * so its connections cannot be judged by one.
     */
    public function testYouTubeDoesNotAuthenticateWithOAuth(): void
    {
        self::assertFalse(
            YouTubeProvider::usesOAuth(),
            'YouTube reads a public channel with an API key — there is no token to store or refresh.'
        );
    }

    public function testInstagramAuthenticatesWithOAuth(): void
    {
        self::assertTrue(InstagramProvider::usesOAuth());
    }

    /**
     * The base class answers for any provider that does not care to, so extending it
     * stays the straightforward way to register one.
     */
    public function testTheBaseClassAnswersBothQuestionsByDefault(): void
    {
        self::assertTrue(InstagramProvider::usesOAuth());
        self::assertTrue(InstagramProvider::usesExcludeNonFeed());
    }

    /**
     * Both providers shipped with the plugin satisfy the contract — the assertion that
     * fails first if a method is added to the interface without an implementation.
     */
    public function testTheShippedProvidersSatisfyTheContract(): void
    {
        foreach ([InstagramProvider::class, YouTubeProvider::class] as $class) {
            self::assertTrue(
                (new ReflectionClass($class))->implementsInterface(ProviderInterface::class),
                $class . ' must satisfy the provider contract.'
            );
        }
    }
}
