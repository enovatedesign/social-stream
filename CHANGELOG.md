# Changelog

## 1.0.3 - 2026-04-22

### Added

- `social-stream/refresh` is now a consolidated cron entry point: each invocation pre-warms the stream cache *and* queues a token refresh for any connection whose Instagram token is within 7 days of expiry. A single cron line is all that's needed — no separate daily token-refresh cron.
- `--force-token` flag on `social-stream/refresh` for queueing a token refresh regardless of expiry (useful after re-authenticating or rotating the Instagram app secret).
- `TokenService::REFRESH_THRESHOLD_DAYS` constant as the single owner of the "expiring soon" policy.

### Changed

- Both cron commands are now safe to run on every web host in a load-balanced setup. Before pushing a job, the plugin checks the Craft queue table (via the primary DB, so replica lag can't mislead it) and skips the push if an identical pending job is present or if one failed within the last two hours.
- `social-stream/token/refresh` now pushes work through `RefreshTokenJob` instead of calling `TokenService::refreshToken()` synchronously in the console process. The manual command behaves the same way from the user's perspective but benefits from the queue-table dedup and the existing retry-with-backoff logic.
- README recommends running `social-stream/refresh` roughly every 30 minutes (half of the default `cacheDuration`) with random minute offsets, rather than every 15 minutes on the hour — reduces wasted API calls and avoids every install hitting Meta simultaneously.
- `RefreshStreamJob` and `RefreshTokenJob` descriptions now include a canonical, locale-independent dedup tag (e.g. `[social-stream:refresh-stream:1:instagram]`) so the queue-table check works regardless of which locale the console runs in.

### Fixed

- Token refresh was not load-balancer safe: multiple web hosts running the cron at the same minute would race on `/refresh_access_token` calls and writes to `ConnectionRecord`, potentially leaving a stale `tokenExpiresAt`. The new queue-table dedup makes the operation idempotent across hosts.
- Stream refresh dedup previously relied on a per-host cache fingerprint, which only worked when the cache backend was shared (Redis/DB). With a per-host file cache every host enqueued its own job. The queue-table check now provides a correctness floor beneath the existing cache fast-path.

## 1.0.2 - 2026-04-22

### Changed

- Install instructions in the README now reflect the plugin's availability on Packagist — installation is a plain `composer require enovate/social-stream`, no path/VCS repository required.
- The Extending section no longer claims custom providers must implement `displayName()` — the abstract requirement was dropped in 1.0.1, so it's now documented as an optional override.

## 1.0.1 - 2026-04-22

### Fixed

- Fatal error when loading the Stream Preview: `Post::toArray()`, `PostAuthor::toArray()`, and `PostMedia::toArray()` declared `bool $recursive`, which narrowed the parent `yii\base\Model::toArray()` parameter type and violated PHP's LSP rules.
- Stream Preview in the CP rendered post cards without thumbnails. The preview JS was still reading the old Instagram-native keys (`media_url`, `thumbnail_url`, `media_type`, `like_count`, `comments_count`) and has been updated to the provider-agnostic `Post` shape (`images[]`, `videos[]`, `children[]`, `likeCount`, `commentsCount`, `meta.mediaType`), falling back to the first carousel child for the thumbnail.
- `SettingsController::actionSave()` called `TokenService::getConnection()` without a provider handle; it now passes `'instagram'` so saving settings works on sites that don't yet have a connection loaded.

### Changed

- Removed the abstract `displayName()` requirement from `enovate\socialstream\base\Provider`; providers no longer need to implement it.

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
