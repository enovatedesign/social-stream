# Changelog

## 1.3.4 - 2026-08-14

### Fixed

- **Every host in a load-balanced setup queued its own copy of the cron's jobs.** The queue-table dedupe had no lock across it, so hosts sharing a crontab all saw an empty queue and all pushed. Both jobs now hold a database-backed lock across the check and the push — one job per connection, not one per host. Craft's default mutex is the database, so no Redis is needed.
- **A concurrent token refresh could lock a healthy connection out.** Craft's queue mutex decides which runner reserves a job, not how many run at once, so duplicate token jobs could refresh the same credential together. The loser was refused and wrote `needsReauthAt` over the successful refresh, after which the cron skipped that connection until someone re-authorised by hand. `RefreshTokenJob` now locks before refreshing.

> {note} Both are structural races found by code review, not reported failures.

### Changed

- `pushIfNotQueued()` on both jobs now returns `bool` rather than `void`. Callers previously reported success unconditionally — `social-stream/refresh` counted jobs it had skipped, and **Refresh Stream Now** claimed a job was queued when it wasn't.

## 1.3.3 - 2026-07-31

### Fixed

- **A small `limit` combined with filtering returned almost nothing.** The caller's `limit` was passed straight through as the Instagram API's page size, but `excludeNonFeed` and `mediaType` are applied here rather than by the API — so asking for 3 posts fetched 3 candidates, and whatever the filter rejected was simply lost. The tighter the limit, the worse it got. On a reels-heavy account, where Instagram reports `is_shared_to_feed: false` for the overwhelming majority of posts, a homepage asking for 3 scanned 9 posts across the full 3-page budget and rendered 1. The API page size is now independent of the limit: when a filter is active the provider requests a full page (25 by default) and keeps paging until the limit is met, so the same request is typically satisfied by a single API call instead of exhausting the page budget. With no filter active the limit is still used as the page size, since every item returned is kept.

### Added

- `fetchPageSize` config setting — how many items to request per API page when filtering is active. Defaults to 25 and is clamped to 1–100, the range Instagram accepts. Raise it for accounts where the filter rejects nearly everything, to satisfy a limit in fewer API calls.

### Changed

- **Default Post Limit** now describes itself as the number of posts to *return*, not to *fetch*. The two were the same thing until this release; now that a filtered request over-fetches, "fetch" described what `fetchPageSize` does, and the setting read as though it capped API traffic. Wording only — the setting's behaviour is unchanged.

## 1.3.2 - 2026-07-31

### Fixed

- **A video Instagram withholds the media URL for came back with no media at all.** Meta omits `media_url` from a video's response when the media contains copyrighted content — most often a reel with licensed audio — and it starts doing so long after the post was published, so a working feed breaks with no code change. `buildMedia()` required a URL before it would build anything, so those posts came back with empty `images`, empty `videos` and no children, and any template indexing `post.images[0]` died with `Key "0" does not exist as the sequence/mapping is empty`. The thumbnail is still served in those responses, so it is now used as the post's image: the post renders as a still linking out to its permalink, which is where the video plays anyway. `videos` is deliberately left empty rather than carrying an entry with a null `url`, so a template's `videos|length` check still means "there is something to play".
- `Post::hasMedia()` now counts carousel children. It only looked at the post's own `images` and `videos`, which are always empty on an album — so the one method templates would reach for as a "can I render this?" guard reported false for every carousel.
- The API Version section of the README named the wrong host. It has said the plugin talks to `graph.facebook.com` since 1.0.0; `InstagramProvider` has always used `graph.instagram.com`. Documentation only — no behaviour changed.

### Changed

- The README's stream example filters on `hasMedia()`, and the Post Properties section documents both that method and the withheld-`media_url` behaviour. The example previously indexed `images[0]` unguarded, which is the pattern that fails as soon as one post arrives without media.
- A troubleshooting entry covers a video rendering as a still image, since the cause is entirely upstream and there is nothing to fix on the Craft side. It notes how to tell such a post apart from a genuine photo (`meta.mediaType` of `VIDEO` with an empty `videos`) for templates that want to overlay a play badge.

## 1.3.1 - 2026-07-30

### Changed

- Reworked the README setup steps, which were in places misleading or no longer matched Meta's UI. A "Before you start" section now states up front that authorisation has to happen on a publicly accessible URL, and lists the two steps that require the Instagram account login — the tester-role approval and the OAuth flow itself. The plugin-side steps are now step 4 of a single numbered sequence rather than a separate section, the "Add account" button under Meta's "Generate access tokens" is explicitly called out as unnecessary, and the note about which environment to authorise in refers to the domain entered in the **OAuth redirect URIs** field rather than "production".

## 1.3.0 - 2026-07-28

> Contains a schema change. Run `php craft up` (or `php craft migrate/all`) after updating.

### Added

- Connections now record a `needsReauthAt` timestamp when the provider rejects the stored credential outright (Instagram OAuthException code 190 — expired, revoked, or invalidated by a password change). The CP reports "Instagram has rejected this token" with the date it was first seen, instead of inferring the state from a stored expiry date that may itself be stale. Cleared automatically by a successful fetch, token refresh, or re-authorisation.
- While a connection is flagged, stream API calls are suspended rather than repeating a request that cannot succeed. A single request is let through every 60 minutes as a probe, so a transient rejection recovers unattended; **Test Connection** and re-authorising also clear the flag immediately. The consolidated cron stops queueing token refreshes for a rejected credential and says so in its output.
- A failed stream fetch is now remembered for 5 minutes and replayed from that memory rather than repeated. Failed responses are still never cached as content, but without this an upstream outage would mean one live API call per uncached request, since there is no cached response to serve.

### Fixed

- **An Instagram API failure was reported as a successful, empty stream.** `fetchMediaPage()` returned `null` both for "no more pages" and for a failed request, and `doFetchStream()` treated the failure as the end of pagination — returning `success: true` with zero posts. That response was then cached for the full TTL, and the successful-fetch bookkeeping cleared the `lastError` that had been written moments earlier. The net effect: a dead token produced a silently blank stream, a fresh "Last Successful Fetch" timestamp, and no error anywhere in the CP. Failures now return `success: false` with the provider's message, are never cached, and leave `lastError` intact.
- A failure part-way through pagination now fails the whole fetch rather than returning the pages collected so far. Returning them would be reported as a success, which would clear the error state just recorded and cache a silently truncated stream — the same failure mode in a subtler form.
- `RefreshTokenJob` now throws once its retries are exhausted, so an unrecoverable token refresh appears as a failed job in the Queue Manager. It previously logged an error and returned normally, which Craft treats as success — leaving no trace in the CP.
- `TokenService::refreshToken()` checks the result of saving the connection record. A failed write previously still logged "Successfully refreshed token" and reported success, which would have silently discarded a newly issued token.
- `doFetchProfile()` no longer tries to read the Guzzle response body twice on a `ClientException`; the stream had already been consumed, so the provider's error message was being replaced by Guzzle's generic one.
- Timestamps written to the connection record now use UTC consistently — `lastFetchAt` and `lastErrorAt` previously used PHP's default timezone in the provider, and `tokenExpiresAt` was written in the system timezone while being read back by helpers that treat a bare database string as UTC. On installs not running UTC, the CP displayed the token expiry offset from its true value and the 7-day refresh threshold was measured against the wrong instant. Existing expiry values are reinterpreted as UTC on upgrade — a shift of at most a few hours against a 60-day window, corrected at the next refresh.
- A successful **Test Connection** no longer stamps `lastFetchAt` or clears `lastError`. It fetches no posts, so it has nothing to report about the stream; erasing the error someone opened the CP to read was actively unhelpful. It still clears the re-auth flag, which is what it does prove.
- `RefreshTokenJob` no longer works through its retry schedule for a credential the provider has rejected outright. The first rejection sets the flag, and the job stops there instead of replaying three more requests Meta has already refused.
- `resolveUserId()` now routes its API errors through the same handler as the rest of the provider, so a rate limit hit on the user-ID lookup enters the cooldown instead of being retried on every cold miss.
- The re-auth flag is read through accessors that tolerate the column being absent, so the window between `composer update` and `php craft up` degrades to "not flagged" rather than throwing on every front-end stream fetch and cron run.
- The `ClientException` path in `TokenService::refreshToken()` checks its `save()` too, so a failure to persist the rejected-token state is logged rather than silently leaving the connection unflagged.

## 1.2.1 - 2026-07-28

### Added

- README troubleshooting section for Meta's "Insufficient developer role" error during authorisation, which happens when the browser is signed into an Instagram account that hasn't accepted the tester invite.

### Fixed

- `icon-mask.svg` is now a flat single-colour shape rather than a clipped, multi-layer drawing, so Craft's CP nav renders the masked icon correctly.
- `RefreshStreamJob` failed with "Calling unknown method: `enovate\socialstream\jobs\RefreshStreamJob::hasEventHandlers()`" on every background refresh in 1.2.0. Queue jobs extend `BaseObject` rather than `Component`, so they have no instance-level event methods; the new refresh event is now dispatched through Yii's class-level `Event` API instead. Because the failure happened before the cache write, affected installs stopped refreshing their streams entirely — anyone on 1.2.0 should upgrade. Subscribing is unchanged: `Event::on(RefreshStreamJob::class, RefreshStreamJob::EVENT_AFTER_REFRESH_STREAM, ...)`.

## 1.2.0 - 2026-05-08

### Added

- `RefreshStreamJob::EVENT_AFTER_REFRESH_STREAM` event, fired after a successful background refresh replaces the cached stream payload. Listeners receive a `StreamRefreshedEvent` with `siteId`, `provider`, `options`, and the full provider `response`. Useful for invalidating downstream caches (e.g. a CDN / Varnish fronting pages that render the stream). Not fired on cold-miss synchronous fetches, on failed refreshes, or when the new payload is byte-identical to what was already cached.

### Fixed

- Plugin icons were placed at the plugin root in 1.1.1, but Craft looks for `icon.svg` and `icon-mask.svg` under the plugin's `basePath` (i.e. `src/`). The icons have been moved so the CP nav now uses the masked icon and the Plugins screen uses the colour icon.

## 1.1.1 - 2026-04-24

### Added

- Plugin icons (`icon.svg` and `icon-mask.svg`) at the plugin root.

## 1.1.0 - 2026-04-22

### Changed

- Relicensed from MIT to the standard Craft commercial plugin license ahead of a paid release via the Craft Plugin Store. Each licensed copy permits one production environment; dev and staging installs are unrestricted. See [LICENSE.md](LICENSE.md) for the full terms.

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
