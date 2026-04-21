# Changelog

## 1.0.0 - 2026-04-21

Initial release.

### Added

- Provider abstraction for pulling posts from multiple social networks through a shared caching, OAuth, and error-handling pipeline:
  - `enovate\socialstream\base\Provider` abstract class and `ProviderInterface` for building additional providers.
  - `enovate\socialstream\services\Providers` registry service with `EVENT_REGISTER_PROVIDER_TYPES` for third-party provider registration.
  - Built-in Instagram provider targeting Instagram Graph API **v21.0** via `graph.facebook.com`.
- `Post`, `PostMedia`, and `PostAuthor` models as the canonical cross-provider data shape. `Post->meta` carries provider-specific extras; `Post->raw` preserves the untransformed API response.
- OAuth authentication flow with long-lived token exchange (60-day validity) and token encryption at rest using Craft's security component.
- Token refresh via CLI command (`php craft social-stream/token/refresh`) and queue jobs with exponential backoff.
- Background stream refresh via `RefreshStreamJob` with deduplication, and CLI command `php craft social-stream/refresh` with `--provider=` and `--site=` flags.
- Stream caching with stale-while-revalidate and stampede protection via mutex locks.
- Per-provider + per-site cache tagging so a token refresh for one provider no longer evicts another's cache, with `CacheService::invalidateForProvider()` and `CacheService::invalidateForSiteAndProvider()` helpers.
- Cache keys include a hash of the effective plugin settings (`defaultLimit`, `excludeNonFeed`) so editing these in the CP transparently invalidates stale entries without a manual cache clear.
- Rate-limit detection (HTTP 429 / OAuthException code 4) with a 15-minute cooldown, scoped per provider + site.
- Integration with Craft's Utilities cache clearing (tag-based invalidation).
- Configurable post limit (1–100) at both CP and template level.
- Filter by `media_type` (IMAGE, VIDEO, CAROUSEL_ALBUM) and by `is_shared_to_feed` to exclude Reels-only posts.
- Automatic carousel children fetching for CAROUSEL_ALBUM posts.
- Multi-site support with independent connections and settings per site.
- Connection Health panel showing token status, fetch history, rate-limit cooldown, and API version, with Test Connection and Refresh Stream Now actions.
- Optional JSON API endpoint at `/actions/social-stream/api` with bearer-token authentication.
- Lifecycle events `Provider::EVENT_BEFORE_FETCH_STREAM` and `EVENT_AFTER_FETCH_STREAM` — set `$event->handled` + `$event->result` to short-circuit, or mutate `$event->result` to transform the response.
- Twig variables: `craft.socialStream.getStream()` and `craft.socialStream.getProfile()` (both require a `provider` option).
- Config file overrides via `config/social-stream.php`.
- Custom log target (`storage/logs/social-stream.log`).
