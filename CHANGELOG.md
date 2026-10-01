# Changelog

## 1.4.0 - 2026-09-28

### Added

- **YouTube support.** A second provider, reached the same way as Instagram: `craft.socialStream.getStream({ provider: 'youtube' })`. Videos and Shorts map into the same `Post` model, so a template that already renders an Instagram feed renders a YouTube one with no new interface to learn — everything provider-specific lives in `post.meta`. Enter an API key and a channel on the new **YouTube** page in the CP, after creating a Google Cloud project with the YouTube Data API v3 enabled.
- **Shorts detection.** The Data API has no field saying whether a video is a Short, so the plugin infers it: YouTube's oEmbed endpoint returns portrait dimensions at a `/shorts/` URL for a genuine Short and landscape for everything else, with a `/shorts/` page request as a fallback for the private and embed-disabled videos oEmbed refuses. Answers are cached permanently per video (a video's status can't change), failures are never cached, and the lookups are concurrent under a time budget so a cold 50-video page can't stall a page load. Filter with `mediaType: 'SHORT'` or `'VIDEO'`, or read `post.meta.isShort`. Duration is deliberately not used — the Shorts limit is now 3 minutes, so length can't separate a 90-second Short from a 90-second video.
- **Push notifications for new uploads.** The plugin subscribes to the channel's Atom feed through Google's WebSub hub, so an upload triggers a background refresh within seconds instead of waiting out the cache. Notifications are authenticated with HMAC-SHA1 over a per-connection secret. Leases cap at 10 days and are renewed by a self-rescheduling queue job, or by the new `php craft social-stream/web-sub/renew` cron — both idempotent, so running both is safe. If neither runs, the feed still updates via the existing refresh cron; push only decides whether that takes seconds or up to an hour.
- **YouTube authenticates with an API key.** Creating a Google Cloud project and enabling the Data API is unavoidable — it is where any YouTube credential comes from — but nothing beyond it is: no OAuth consent screen, no redirect URI to register character for character, no app to publish, no unverified-app warning, and no refresh token to be rejected. Setup is: create a key, restrict it to the server's IP and the YouTube Data API, paste it in with the channel to read. The trade is private and unlisted videos, which no API key can see.
- **The channel is named, not discovered.** OAuth's `mine=true` is the one call that genuinely needed an authorised user, so the channel is configured instead. The field takes whatever is in the address bar — a handle URL, an `@handle`, a `/channel/UC…` URL, a bare channel ID, a legacy `/user/` URL, or a link to any video on the channel — and resolves it on save, showing the channel it found so a mistyped handle (which is very often a *valid* handle belonging to someone else) is caught there rather than when the site fills with a stranger's uploads. What gets stored is the canonical `UC…` ID: handles can be renamed and reclaimed, so keying on one would let a feed quietly change channel.
- `usesOAuth()` on `base\Provider`. A provider returning `false` has no authorisation flow, no token to store and nothing to refresh — the CP hides the connect button, and the refresh cron and `social-stream/token/refresh` skip it instead of reporting a failure once a run.
- **YouTube health panel** — channel, resolved channel ID, WebSub lease, last notification, and the day's API quota against the 10,000-unit limit. The Push Notifications row carries a **Subscribe** / **Renew** button: subscribing is automatic on connect and renews itself, but a hub that was unreachable at that moment used to leave the connection with no way back except a console command. Plus **Test Connection**, **Refresh Stream Now** and **Disconnect**, which unsubscribes from the hub and forgets the channel while keeping the API key — it belongs to the Cloud project, not the channel.
- **A Providers tab**, listing every registered provider with its status, connected account and last successful fetch, and a **Connect** or **Configure** button leading to that provider's own page. Providers get a page each rather than a tab each, so the settings screen doesn't grow a tab every time one is registered — and a provider added by another plugin now appears with a real status and a working page instead of being invisible in the CP.
- `usesExcludeNonFeed()` on `base\Provider` — a provider returning `false` has the option normalised out of its cache keys. `enterRateLimitCooldown()` now takes an optional TTL, for a provider whose limit is a daily quota rather than a rolling window.
- Config settings `shortsDetection`, `shortsRedirectFallback` and `shortsLookupBudget`.
- `--provider` on `social-stream/token/refresh`, which previously only ever refreshed Instagram.
- **Connecting an account now names it.** The CP never fetches a profile itself — opening a settings page must not spend a provider's quota — so until something else happened to fetch one, a perfectly healthy connection was identified in the Providers table and its own panel by a raw `UCtljUyou0OSIYBc0ajeSgDg` or `17841413269526561`. Both providers now fetch the profile once at the moment of connecting, which is also the point at which someone is looking at the screen and can check it found the right account. YouTube's panel leads with the `@handle` and demotes the channel ID to a footnote, and `@handle` is what the Account column shows, matching Instagram's username.
- **The Push Notifications row distinguishes "requested" from "not subscribed".** The hub verifies a subscription by calling back, so the lease doesn't exist yet when the page that triggered it renders — it would report "Not subscribed" for the couple of seconds before the callback landed, which is the one thing that hadn't happened.
- **Stream Preview tiles now follow the provider.** YouTube posts get a 16:9 tile matching the player rather than Instagram's square, which was cropping the sides off every thumbnail. A Short — or any YouTube thumbnail taller than it is wide — is centred in that tile over a blurred copy of itself instead of being cropped, so portrait and landscape uploads sit in the same grid. Instagram's tile is unchanged.

### Fixed

- **Dates in the control panel are shown in the site's time zone.** Connections record their diagnostics as UTC, and the settings screens handed those bare strings straight to Twig's `|date` filter — which reads a string carrying no offset in the *server's* zone rather than the one it was written in. The UTC clock time was therefore printed verbatim: an hour out through British summer, further out the further the site sits from Greenwich, and a day out either side of midnight. Last successful fetch, last error, token expiry, "rejected since", the WebSub lease and the last push notification are all converted now. Only the display was ever affected — the code that reads these columns to make decisions already used `DateTimeHelper::toDateTime()`, which assumes UTC for a bare database string.
- **Choosing a provider to preview no longer counts as an unsaved change.** The Stream Preview dropdown is read by JavaScript for an AJAX call and never submitted, but it carried a `name` — so it formed part of the serialised value Craft snapshots to detect unsaved changes. Picking a provider, or arriving from a provider page's **Preview Stream** button, left the settings form looking edited, and clicking away to another page raised the browser's "Leave site?" warning over a choice there was nothing to save.
- **An option a provider ignores no longer splits its cache in two.** `excludeNonFeed` is an Instagram concept, and it was keyed into every provider's cache entries — both directly and through the settings hash. A provider that ignores the flag would have stored two identical copies of the same stream, keyed only on a value that changed nothing about it, and filled both with their own API calls.
- **The JSON API endpoint returned a 500 on every request.** `ApiController` declared `enableCsrfValidation` as `bool`, but `yii\web\Controller` declares it untyped — and PHP treats a typed redeclaration in a subclass as a fatal error when the class is loaded, so the endpoint died before any of its code ran. Present since the endpoint shipped in 1.0.0; it is off by default, which is why it went unreported. The same mistake was caught in the new webhook controller, where it broke YouTube's subscription handshake.
- **The Instagram App ID and Secret are no longer marked required in the CP form.** With one form now saving both providers' credentials, browser validation on the Instagram fields would have blocked a YouTube-only site from saving the page at all.

### Changed

- The **Connection** tab is gone: Instagram's credentials and health panel moved to its own page, reached from the Providers tab. The Stream Preview tab covers whichever providers are connected, with a picker when more than one is.
- A connection that renews its access token inline is skipped by the cron's token-refresh step. A YouTube token expires hourly, so a threshold measured in days would have queued a job on every single run — and learned nothing, since the stream pre-warm already exercises the same renewal path and surfaces a dead refresh token once per run.
- `Post::fromArray()` rebuilds a timestamp at the offset it was serialised with rather than converting it to the system timezone, making the cache round trip exactly reversible. The instant is unchanged, and Twig's `date` filter formats in the app's timezone either way, so nothing rendered changes.
- `isConfigured()` reads the stored credential instead of asking for a usable token, so asking whether a provider is configured can no longer trigger an HTTP request to it.
- **The connected account's name is now remembered whenever any profile is fetched**, not only when one happens to be cached. `Provider::fetchProfile()` records it, so the CP names the account after an OAuth connection, a Test Connection or a template call alike — previously a freshly connected YouTube channel showed its raw `UC…` identifier until something else fetched a profile.
- **Test Connection** now caches the profile it fetched. It was throwing the response away, so the call was paid for twice and the CP had nothing to name the connected account with — which is why a healthy Instagram connection showed no account at all until a template happened to call `getProfile()`. The account name is also remembered for 30 days, well past the stream cache's TTL, so it doesn't blank out an hour later; failing all that, the table falls back to the identifier stored on the connection.

> {warn} This release adds four columns to the connections table. Run `php craft up` after updating.

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
