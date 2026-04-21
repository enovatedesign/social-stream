# YouTube Provider — Implementation Plan

A plan for adding YouTube channel video support (regular videos and Shorts) to the Social Stream plugin as a new provider alongside Instagram. This document reflects the current architecture of the plugin — the provider abstraction is already in place, so most of the cross-cutting routing work is done.

---

## Overview

YouTube integration adds a new provider that plugs into the existing `Provider` / `Providers` abstraction. Templates use the same `craft.socialStream.getStream({ provider: 'youtube', … })` entry point and receive the same `Post` model as Instagram.

The YouTube Data API v3 is used for all API communication, with Google OAuth 2.0 for authentication and WebSub (PubSubHubbub) for real-time notifications.

**Key differences from Instagram:**

| Concern | Instagram | YouTube |
|---|---|---|
| API | Instagram Graph API (v21.0) | YouTube Data API v3 |
| Auth | Instagram OAuth → long-lived 60-day token | Google OAuth 2.0 → permanent refresh token + 1-hour access token |
| Feed endpoint | Single `GET /{userId}/media` | Two-step: get uploads playlist ID, then `GET playlistItems.list` + batched `GET videos.list` |
| Rate limits | HTTP 429 / error code 4, per-account | Quota-based: 10,000 units/day, per-project |
| Push notifications | Meta webhook, HMAC-SHA256 | WebSub (PubSubHubbub), HMAC-SHA1, 10-day lease renewal, Atom XML |
| Content types | IMAGE, VIDEO, CAROUSEL_ALBUM | Regular videos, Shorts (no official API field — heuristic detection) |
| Thumbnails | Single `media_url` | Multiple sizes: default (120x90) through maxres (1280x720) |

---

## What's already in place

The recent `Refactor for provider independence` commit (`e988ccc`) pushed the provider abstraction further than the original YouTube plan assumed. Before writing YouTube-specific code, note what you can build against:

- **Provider contract**: [src/base/ProviderInterface.php](src/base/ProviderInterface.php) and the abstract [src/base/Provider.php](src/base/Provider.php) base class. Subclasses implement `handle()` (static), `displayName()` (static), `doFetchStream(array)`, `doFetchProfile(int)`. The base class handles rate-limit cooldown, `recordError()`, `updateLastFetch()`, event emission, and the canonical error-response shapes.
- **Generic `Post` model**: [src/models/Post.php](src/models/Post.php) with `PostAuthor` and `PostMedia` sub-models, plus `$meta` (provider-specific fields) and `$raw` (original API payload). Already supports `toArray()` / `fromArray()` round-trips. **YouTube maps into this same model — there is no `YouTubePost` class.**
- **Provider registry**: [src/services/Providers.php](src/services/Providers.php) with `requireProviderByHandle(string)`. Plugins register providers via `EVENT_REGISTER_PROVIDER_TYPES` — see the existing registration at [src/SocialStream.php:209-218](src/SocialStream.php#L209-L218).
- **Routing through the variable**: [src/variables/SocialStreamVariable.php](src/variables/SocialStreamVariable.php) — `getStream()` and `getProfile()` already require a `provider` option and dispatch through `requireProviderByHandle()`. No changes needed.
- **Cache keys and serialisation**: [src/services/CacheService.php](src/services/CacheService.php) — feed + profile keys are already provider-scoped (`social-stream:{siteId}:{provider}:…` and `social-stream:profile:{provider}:{siteId}`), tag-based invalidation is per-site-per-provider, and serialisation uses `Post::toArray()` / `Post::fromArray()` generically.
- **Queue refresh**: [src/jobs/RefreshStreamJob.php](src/jobs/RefreshStreamJob.php) — already carries a `$provider` property and resolves the provider via `Providers::getProviderByHandle()`. `pushIfNotQueued()` accepts `$provider`.
- **Console commands**: `social-stream/refresh` and `social-stream/token/refresh` are already provider-aware (accept `--provider=…`).
- **Connection schema**: [src/records/ConnectionRecord.php](src/records/ConnectionRecord.php) already has a `providerUserId` column — **YouTube can reuse this for the channel ID**, no new column needed.

---

## What still needs doing

Gaps verified in the current codebase:

1. **Google OAuth token exchange** — [src/services/TokenService.php:138-178](src/services/TokenService.php#L138-L178) (`exchangeAuthCode(string $code, int $siteId)`) hardcodes `'instagram'` at line 140 and the Instagram short→long exchange endpoints. Needs a `$provider` parameter and a Google branch.
2. **Google refresh grant** — [src/services/TokenService.php:259-312](src/services/TokenService.php#L259-L312) (`refreshToken()`) already has a `$provider` parameter but unconditionally hits the Instagram `ig_refresh_token` endpoint. Needs a Google branch and `invalid_grant` handling.
3. **Access-token auto-refresh** — `TokenService::getAccessToken()` returns whatever is stored. YouTube access tokens expire hourly, so it needs to refresh inline when expiry is within ~5 minutes.
4. **OAuth state encoding** — [src/controllers/AuthController.php:54](src/controllers/AuthController.php#L54) passes `state=$siteId` (plain integer). The callback at line 69 reads it back the same way. Needs a JSON+base64 wrapper carrying `{siteId, provider}`, with legacy-integer backward-compat for any Instagram OAuth redirects in flight during upgrade.
5. **`actionCallback` provider routing** — [src/controllers/AuthController.php:91](src/controllers/AuthController.php#L91) calls `exchangeAuthCode($code, $siteId)` with no provider. Needs to forward the decoded provider and route the post-exchange validation (Instagram's `_validateAccountType()` vs YouTube's channel lookup) accordingly.
6. **`excludeNonFeed` cache-key noise for YouTube** — `CacheService::streamKey()` includes `excludeNonFeed` even when the provider doesn't use it. Normalise to `0` when `provider === 'youtube'` so YouTube doesn't get two identical cache entries keyed only on an unused flag.
7. **Schema additions** — see the migration section below.
8. **The provider itself, plus WebSub and CP UI** — covered below.

---

## Architecture

### New files

```
src/
  providers/
    YouTubeProvider.php              # Extends base\Provider; YouTube Data API v3 calls
  jobs/
    RenewWebSubJob.php               # Self-rescheduling WebSub lease renewal
  console/
    controllers/
      WebSubController.php           # php craft social-stream/web-sub/renew
src/migrations/
  m{date}_add_youtube_fields.php     # refreshToken, websubExpiresAt columns
```

### Modified files

```
src/
  SocialStream.php                   # Register YouTubeProvider via EVENT_REGISTER_PROVIDER_TYPES
  services/TokenService.php          # $provider on exchangeAuthCode(), Google refresh branch,
                                     # auto-refresh in getAccessToken(), new getRefreshToken() helper
  controllers/AuthController.php     # Base64-JSON state encoding; Google OAuth URL builder;
                                     # provider-aware callback routing
  controllers/WebhookController.php  # Add youtube-verify / youtube-handle actions
  services/CacheService.php          # Normalise excludeNonFeed to 0 for YouTube in streamKey()
  records/ConnectionRecord.php       # Add @property refreshToken, websubExpiresAt
  translations/en/social-stream.php  # New strings for YouTube UI and error messages
  templates/settings/…               # New YouTube tab/section in CP settings
```

`SocialStreamVariable`, `RefreshStreamJob`, `Providers`, `CacheService` (apart from the `excludeNonFeed` normalisation), and the `social-stream/refresh` + `social-stream/token/refresh` console commands need **no changes** — they are already provider-agnostic.

---

## Database changes

Add two nullable columns to `socialstream_connections`:

| Column | Type | Notes |
|---|---|---|
| `refreshToken` | `TEXT NULL` | Google OAuth refresh token (encrypted). Instagram does not use it — stays `NULL` for Instagram rows. |
| `websubExpiresAt` | `DATETIME NULL` | When the current WebSub subscription lease expires. Used by the health panel and the renewal job. |

**Reuse the existing `providerUserId` column for the YouTube channel ID** (`UCxxxxxx…`). It's already used by Instagram for `user_id` and has exactly the same semantics — a provider-specific stable identifier for the connected account. No new column.

No changes to `socialstream_settings` — the per-site settings (`defaultLimit`, `cacheDuration`, `excludeNonFeed`, `secureApiEndpoint`, `apiToken`) apply across providers.

---

## Authentication & Token Management

### Google OAuth 2.0 flow

1. Admin enters **Google Client ID** and **Client Secret** in the CP settings for the site (env-aware via `App::parseEnv()`), stored encrypted in `appId` / `appSecret` on the YouTube `ConnectionRecord` row.
2. "Connect YouTube" button → `AuthController::actionHandleAuth?provider=youtube&siteId=N` → Google's authorisation screen.
3. Callback exchanges the code for an **access token** (1-hour) and **refresh token** (permanent-ish — see "Testing mode" below).
4. Both tokens encrypted and stored. Channel ID fetched via `channels.list?mine=true` and stored in `providerUserId`.
5. Before every YouTube API call, `TokenService::getAccessToken()` checks `tokenExpiresAt` and refreshes inline if expired or within 5 minutes of expiry — transparent to `YouTubeProvider`.

### OAuth endpoints

| Purpose | URL |
|---|---|
| Authorisation | `https://accounts.google.com/o/oauth2/v2/auth` |
| Token exchange | `https://oauth2.googleapis.com/token` |
| Token revocation (optional) | `https://oauth2.googleapis.com/revoke` |

### Authorisation URL parameters

```
https://accounts.google.com/o/oauth2/v2/auth?
  client_id={CLIENT_ID}&
  redirect_uri={REDIRECT_URI}&
  response_type=code&
  scope=https://www.googleapis.com/auth/youtube.readonly&
  access_type=offline&
  prompt=consent&
  state={ENCODED_STATE}
```

- `access_type=offline` is required to receive a refresh token.
- `prompt=consent` forces the consent screen so a fresh refresh token is issued on every authorise.
- `youtube.readonly` is sufficient for reading channel info and videos.

### OAuth state encoding

The current `AuthController` passes `state=$siteId` (plain integer at [src/controllers/AuthController.php:54](src/controllers/AuthController.php#L54)). For multi-provider support over a single callback URL, carry both the site ID and the provider:

```php
// Initiation — actionHandleAuth()
$state = base64_encode(json_encode([
    'siteId' => $siteId,
    'provider' => $provider, // 'instagram' or 'youtube'
]));

// Callback — actionCallback()
$stateParam = Craft::$app->request->getQueryParam('state');

if (is_numeric($stateParam)) {
    // Legacy format: plain siteId (Instagram only). Keep for the upgrade window.
    $siteId = (int) $stateParam;
    $provider = 'instagram';
} else {
    $stateData = json_decode(base64_decode($stateParam), true) ?: [];
    $siteId = (int) ($stateData['siteId'] ?? 0);
    $provider = $stateData['provider'] ?? 'instagram';
}
```

Both providers share `/actions/social-stream/auth/callback`; the state decides the flow.

### Token exchange

```
POST https://oauth2.googleapis.com/token
Content-Type: application/x-www-form-urlencoded

code={CODE}&
client_id={CLIENT_ID}&
client_secret={CLIENT_SECRET}&
redirect_uri={REDIRECT_URI}&
grant_type=authorization_code
```

Response:

```json
{
  "access_token": "ya29.xxx",
  "refresh_token": "1//xxx",
  "expires_in": 3600,
  "token_type": "Bearer"
}
```

### Token refresh

```
POST https://oauth2.googleapis.com/token
Content-Type: application/x-www-form-urlencoded

refresh_token={REFRESH_TOKEN}&
client_id={CLIENT_ID}&
client_secret={CLIENT_SECRET}&
grant_type=refresh_token
```

Check `tokenExpiresAt` at the start of every YouTube API call. If expired or within 5 minutes of expiry, refresh first. Keep this in `TokenService::getAccessToken()` so `YouTubeProvider` never sees an expired-token error.

### Google OAuth "Testing" mode warning

Google apps in "Testing" mode (not verified through Google's OAuth consent screen review) issue refresh tokens that **expire every 7 days**. This means YouTube silently disconnects weekly until the app is published.

For most plugin users (connecting their own channel to their own site), the app only needs to be *published* — it does **not** need full verification review unless external users will use it. Publishing requires basic branding and a privacy policy URL.

**Detection**: When `TokenService::refreshToken()` receives `error: invalid_grant` from Google, it should:

1. Clear `accessToken` and `refreshToken` on the connection.
2. Set `lastError` to: `"YouTube refresh token expired or revoked. Please reconnect your YouTube channel. If this happens every 7 days, your Google app may be in Testing mode — see the documentation for how to publish it."`
3. Log a warning with the same guidance.

Document prominently in the README.

### Token lifecycle differences

| Concern | Instagram | YouTube |
|---|---|---|
| Long-lived credential | Access token (60-day, must be refreshed before expiry) | Refresh token (permanent unless revoked or unused for 6 months — but see Testing mode) |
| Short-lived credential | N/A | Access token (1-hour, auto-refreshed) |
| Refresh mechanism | `GET /refresh_access_token?grant_type=ig_refresh_token` | `POST oauth2.googleapis.com/token` with `grant_type=refresh_token` |
| Cron necessity | Essential (prevent 60-day expiry) | Optional — access token auto-refreshes inline. Cron is only valuable as an early-warning probe for revoked refresh tokens |

### TokenService changes (concrete)

- `exchangeAuthCode(string $code, int $siteId, string $provider = 'instagram')` — **add `$provider` parameter**. Branch on provider: Instagram uses the existing short→long exchange (unchanged); YouTube POSTs to Google's token endpoint, stores both `accessToken` and `refreshToken` (encrypted), sets `tokenExpiresAt`, then calls `channels.list?mine=true` to fetch and store the channel ID in `providerUserId`.
- `refreshToken(int $siteId, string $provider)` — branch on provider. YouTube: POST to Google's token endpoint with the refresh token, update `accessToken` + `tokenExpiresAt`. On `invalid_grant`: clear tokens and set the descriptive `lastError` above. Instagram: unchanged.
- `getAccessToken(int $siteId, string $provider)` — for YouTube, if `tokenExpiresAt` is expired or within 5 minutes, call `refreshToken()` before returning.
- `getRefreshToken(int $siteId, string $provider): ?string` — decrypt and return the refresh token. Used by `refreshToken()`.

---

## YouTube → Post mapping

YouTube-specific fields map into the shared [Post](src/models/Post.php) model. The mapping lives inside `YouTubeProvider` (mirroring `InstagramProvider::mapToPost()` at [src/providers/InstagramProvider.php:262-288](src/providers/InstagramProvider.php#L262-L288)).

| Post property | YouTube source |
|---|---|
| `id` | `id` (video ID) |
| `provider` | `'youtube'` |
| `caption` | `snippet.title` (video title is the closest analogue to Instagram's caption) |
| `permalink` | `https://www.youtube.com/watch?v={id}` (or `https://www.youtube.com/shorts/{id}` if `isShort`) |
| `timestamp` | `snippet.publishedAt` (parsed via `DateTimeHelper::toDateTime()`) |
| `likeCount` | `statistics.likeCount` |
| `commentsCount` | `statistics.commentCount` |
| `author` | `PostAuthor` with `id = snippet.channelId`, `name = snippet.channelTitle`, `handle = snippet.channelTitle` |
| `images` | `PostMedia` with `type = TYPE_IMAGE`, `url = best available thumbnail` (see `selectThumbnail()` below) |
| `videos` | `[]` — YouTube does not expose a direct video file URL; playback is via the embed iframe |
| `children` | `[]` |
| `meta` | YouTube-specific fields — see below |
| `raw` | Merged raw API payload (snippet + contentDetails + statistics + status) |

### `meta` keys

Templates and the JSON API endpoint reach the YouTube-specific fields via `post.meta.*`:

```php
$post->meta = [
    'title' => $snippet['title'] ?? null,              // duplicated from caption for clarity
    'description' => $snippet['description'] ?? null,
    'duration' => $contentDetails['duration'] ?? null, // raw ISO 8601, e.g. 'PT1M30S'
    'durationSeconds' => $this->parseDuration($contentDetails['duration'] ?? 'PT0S'),
    'durationFormatted' => $this->formatDuration($durationSeconds), // '1:30' / '2:15:03'
    'definition' => $contentDetails['definition'] ?? null, // 'hd' | 'sd'
    'viewCount' => isset($statistics['viewCount']) ? (int) $statistics['viewCount'] : null,
    'tags' => $snippet['tags'] ?? [],
    'categoryId' => $snippet['categoryId'] ?? null,
    'privacyStatus' => $status['privacyStatus'] ?? null,
    'embeddable' => $status['embeddable'] ?? true,
    'madeForKids' => $status['madeForKids'] ?? false,
    'isShort' => $isShort,
    'mediaType' => $isShort ? 'SHORT' : 'VIDEO', // matches the shape Instagram writes
    'channelId' => $snippet['channelId'] ?? null,
    'channelTitle' => $snippet['channelTitle'] ?? null,
    'thumbnails' => $snippet['thumbnails'] ?? [],
    'embedUrl' => 'https://www.youtube.com/embed/' . $post->id,
];
```

### Thumbnail selection

| Key | Dimensions | Notes |
|---|---|---|
| `default` | 120x90 | Always present |
| `medium` | 320x180 | Always present |
| `high` | 480x360 | Always present |
| `standard` | 640x480 | Not always present |
| `maxres` | 1280x720 | Only if a custom thumbnail is set |

`selectThumbnail($thumbnails)` picks the best available in the fallback chain `maxres → standard → high → medium → default` and writes it as a single `PostMedia` into `$post->images`. Templates needing a specific size read the full map from `post.meta.thumbnails`.

---

## YouTubeProvider

### Class skeleton

```php
namespace enovate\socialstream\providers;

use enovate\socialstream\base\Provider;

class YouTubeProvider extends Provider
{
    public const API_BASE_URL = 'https://www.googleapis.com/youtube/v3';
    public const WEBSUB_HUB_URL = 'https://pubsubhubbub.appspot.com/subscribe';

    public static function handle(): string { return 'youtube'; }
    public static function displayName(): string { return 'YouTube'; }

    protected function doFetchStream(array $options): array { /* … */ }
    protected function doFetchProfile(int $siteId): array { /* … */ }
}
```

The base class ([src/base/Provider.php](src/base/Provider.php)) handles rate-limit suppression, error recording, last-fetch timestamps, and the `beforeFetchStream` / `afterFetchStream` events. `YouTubeProvider` only implements the two `do*` hooks and its own quota-exhaustion cooldown (which reuses `enterRateLimitCooldown()` / `isRateLimited()` — they're not Instagram-specific).

### `doFetchProfile(int $siteId): array`

```
GET /channels?part=snippet,statistics&id={channelId}
```

Return `['success' => true, 'data' => [...], 'error' => null]` with: `id`, `title`, `description`, `customUrl` (@handle), `thumbnailUrl` (highest available), `subscriberCount`, `videoCount`, `viewCount`.

**Quota cost:** 1 unit.

### `doFetchStream(array $options): array`

Options: `siteId`, `limit`, `mediaType` (`VIDEO` | `SHORT` | `null`), `after` (page token).

Three steps:

1. **Get uploads playlist ID** (cache 24h — it never changes for a channel):
   ```
   GET /channels?part=contentDetails&id={channelId}
   ```
   Extract `contentDetails.relatedPlaylists.uploads`. **Quota: 1 unit.**

2. **List playlist items** (paginated):
   ```
   GET /playlistItems?part=snippet,contentDetails&playlistId={uploadsPlaylistId}&maxResults=50&pageToken={pageToken}
   ```
   Collect video IDs. **Quota: 1 unit per page.**

3. **Batch-fetch video details:**
   ```
   GET /videos?part=snippet,contentDetails,statistics,status&id={id1,…,id50}
   ```
   **Quota: 1 unit per batch of 50.** The `status` part is needed for `privacyStatus`, `embeddable`, `madeForKids` and does **not** add quota cost.

Apply `mediaType` filter post-fetch (same pattern as Instagram's `excludeNonFeed`). Over-fetch and paginate to fill the requested `limit`, capped at `maxFetchPages` (from `config/social-stream.php`, default 3, the same knob Instagram uses).

Return:

```php
[
    'success' => true,
    'data' => $posts,                 // Post[]
    'nextCursor' => $nextPageToken,   // YouTube's nextPageToken string
    'error' => null,
    'cached' => false,
]
```

### Shorts detection

YouTube has no official Shorts field. Use a heuristic mirroring Instagram's `is_shared_to_feed` approach:

```php
private function isShort(array $video): bool
{
    $durationSeconds = $this->parseDuration($video['contentDetails']['duration'] ?? 'PT0S');

    if ($durationSeconds > 180) {
        return false; // Shorts max duration is 3 minutes (as of October 2024)
    }

    $title = strtolower($video['snippet']['title'] ?? '');
    $description = strtolower($video['snippet']['description'] ?? '');

    if (str_contains($title, '#shorts') || str_contains($description, '#shorts')) {
        return true;
    }

    // <=60s without the hashtag — assume Short (original Shorts limit)
    // 61–180s without the hashtag — ambiguous, treat as regular
    return $durationSeconds <= 60;
}
```

**Limitations to document:**

- False positives: short regular videos (teasers, music clips, announcements) ≤ 60s
- False negatives: Shorts between 61–180 seconds without the hashtag

Future improvements (not in v1):

- Aspect-ratio signal from the `player` part (`embedWidth` / `embedHeight`) — adds another part to the call but no quota cost.
- Configurable `maxShortDuration` via `config/social-stream.php` (default 60).
- Monitor YouTube's issue tracker [#232112727](https://issuetracker.google.com/issues/232112727) for an official field.

### ISO 8601 duration parsing

Use `DateInterval` rather than regex — it handles edge cases (days on long livestream replays, fractional seconds):

```php
private function parseDuration(string $iso8601): int
{
    try {
        $interval = new \DateInterval($iso8601);
        return ($interval->d * 86400)
            + ($interval->h * 3600)
            + ($interval->i * 60)
            + $interval->s;
    } catch (\Exception $e) {
        SocialStream::warning('Failed to parse duration: ' . $iso8601);
        return 0;
    }
}
```

### Quota tracking

10,000 units/day, resets at midnight Pacific Time.

- Increment `social-stream:youtube-quota:{Y-m-d-PT}` on each API call.
- On HTTP 403 with `reason: quotaExceeded`: call `enterRateLimitCooldown()` (the base-class helper) with a TTL that matches time-until-midnight-PT, and log once.
- Surface the current count in the health panel.

### `excludeNonFeed` ignored

The `excludeNonFeed` option is Instagram-specific. For YouTube, `doFetchStream()` silently ignores it and — critically — `CacheService::streamKey()` normalises it to `0` when `provider === 'youtube'` so the cache doesn't store duplicate entries keyed only on this unused flag.

---

## WebSub (PubSubHubbub) integration

YouTube uses WebSub for push notifications when a channel publishes a new video. This is different from Instagram's Meta webhook system (different signature algorithm, different payload format, lease-based renewal).

### Flow

1. **Subscribe**: POST to `https://pubsubhubbub.appspot.com/subscribe` with the channel's Atom feed URL.
2. **Verify**: The hub GETs the callback URL with `hub.challenge`; echo it back as plain text.
3. **Receive**: Hub POSTs an Atom XML payload when a video is published/updated.
4. **Renew**: Subscriptions lease for a max of **10 days** — must re-subscribe before expiry.

### Subscription request

```
POST https://pubsubhubbub.appspot.com/subscribe
Content-Type: application/x-www-form-urlencoded

hub.callback=https://yoursite.com/actions/social-stream/webhook/youtube-handle
hub.topic=https://www.youtube.com/feeds/videos.xml?channel_id={CHANNEL_ID}
hub.mode=subscribe
hub.lease_seconds=864000
hub.secret={HMAC_SECRET}
```

### Verification (GET)

```
GET /actions/social-stream/webhook/youtube-verify?
  hub.mode=subscribe&
  hub.topic=https://www.youtube.com/feeds/videos.xml?channel_id={CHANNEL_ID}&
  hub.challenge={RANDOM_STRING}&
  hub.lease_seconds=864000
```

Response: HTTP 200 with body = `hub.challenge` (plain text).

### Notification (POST) — Atom XML

```xml
<feed xmlns:yt="http://www.youtube.com/xml/schemas/2015"
      xmlns="http://www.w3.org/2005/Atom">
  <entry>
    <yt:videoId>VIDEO_ID</yt:videoId>
    <yt:channelId>CHANNEL_ID</yt:channelId>
    <title>Video Title</title>
    <published>2025-03-03T12:00:00+00:00</published>
    <updated>2025-03-03T12:00:00+00:00</updated>
  </entry>
</feed>
```

### Signature verification

Header is `X-Hub-Signature: sha1={HMAC_SHA1_SIGNATURE}` — **SHA1**, not SHA256 like Meta's webhook.

```php
hash_equals('sha1=' . hash_hmac('sha1', $rawBody, $secret), $signature)
```

### Subscription renewal

Subscriptions max out at a 10-day lease. Use two redundant mechanisms that coexist safely (both are idempotent — re-subscribing just resets the lease):

- **Primary: cron command** `php craft social-stream/web-sub/renew` (recommended: `0 3 */7 * *` — every 7 days at 03:00, 3-day safety margin).
- **Belt-and-suspenders: `RenewWebSubJob`** — self-rescheduling queue job. After each successful renewal it pushes another copy with a 9-day delay. Covers sites without cron configured.

```php
class RenewWebSubJob extends BaseJob
{
    public int $siteId;

    public function execute($queue): void
    {
        $provider = SocialStream::$plugin->providers->requireProviderByHandle('youtube');
        $result = $provider->subscribeWebSub($this->siteId);

        if ($result['success']) {
            $connection = SocialStream::$plugin->token->getConnection($this->siteId, 'youtube');
            $connection->websubExpiresAt = (new \DateTime())->modify('+10 days')->format('Y-m-d H:i:s');
            $connection->save();

            // Self-reschedule
            Craft::$app->queue->delay(9 * 24 * 3600)->push(new static([
                'siteId' => $this->siteId,
            ]));
        } else {
            // Retry sooner on failure
            Craft::$app->queue->delay(3600)->push(new static([
                'siteId' => $this->siteId,
            ]));
        }
    }
}
```

### WebhookController changes

Instagram's existing `verify` / `handle` actions stay untouched. Add two YouTube-specific actions (separate because verification and payload formats differ):

| Action | Route | Purpose |
|---|---|---|
| `actionYoutubeVerify()` | `GET /actions/social-stream/webhook/youtube-verify` | WebSub challenge response |
| `actionYoutubeHandle()` | `POST /actions/social-stream/webhook/youtube-handle` | Atom XML notification handler |

Both anonymous with CSRF disabled. Signature verification uses HMAC-SHA1 (via the connection's `webhookVerifyToken` set when subscribing).

On a valid notification:

1. Verify HMAC-SHA1.
2. Parse `<yt:channelId>` from the Atom XML.
3. Look up the connection by `providerUserId = {channelId}` and `provider = 'youtube'`.
4. Push `RefreshStreamJob::pushIfNotQueued($siteId, [], 'youtube')`.
5. Invalidate the site's YouTube cache tag (`social-stream:site-provider:{siteId}:youtube`).
6. Return 200.

---

## Provider registration

Register `YouTubeProvider` the same way `InstagramProvider` is registered today ([src/SocialStream.php:209-218](src/SocialStream.php#L209-L218)):

```php
private function _registerDefaultProviders(): void
{
    Event::on(
        Providers::class,
        Providers::EVENT_REGISTER_PROVIDER_TYPES,
        function (RegisterComponentTypesEvent $event) {
            $event->types[] = InstagramProvider::class;
            $event->types[] = YouTubeProvider::class;
        }
    );
}
```

No other routing wiring is needed. `Providers::requireProviderByHandle('youtube')` will resolve the subclass, and every caller (`SocialStreamVariable`, `RefreshStreamJob`, console commands) already dispatches through that.

---

## CP settings UI

### YouTube connection section

Mirrors the Instagram section:

1. **Google Client ID** — autosuggest field (env-aware).
2. **Google Client Secret** — autosuggest field (env-aware).
3. **"Connect YouTube Channel"** button — initiates Google OAuth (disabled until Client ID is saved).
4. **Connected channel display** — name, avatar, `@handle`, subscriber count (when connected).
5. **"Disconnect"** — clears tokens and `providerUserId`.
6. **WebSub webhook status** — active (if `websubExpiresAt > now`) or expired; shows `websubExpiresAt` and `webhookLastReceived`.

### Health panel — YouTube indicators

| Indicator | Source |
|---|---|
| Status | Green (connected + valid refresh token), red (revoked/missing) |
| Channel | Channel name and `@handle` (cached `channels.list` response) |
| Access token | Auto-refreshing — shows "expires in X minutes" but requires no action |
| Refresh token | Present/absent |
| Last successful fetch | `lastFetchAt` |
| Last error | `lastError` + `lastErrorAt` (with specific wording for Google Testing-mode detection) |
| WebSub status | Active/expired from `websubExpiresAt`, plus `webhookLastReceived` |
| Daily quota usage | `{used} / 10,000 units` from `social-stream:youtube-quota:{Y-m-d-PT}` |
| API | YouTube Data API v3 |

### "Test Connection" button

Calls `channels.list?mine=true` (1 quota unit). Displays channel name and subscriber count on success, or the specific error.

---

## Template usage

```twig
{% set feed = craft.socialStream.getStream({
    provider: 'youtube',
    limit: 6,
    siteId: currentSite.id,
}) %}

{% if feed.success %}
    {% for post in feed.data %}
        <div class="video-card">
            <a href="{{ post.permalink }}" target="_blank">
                {% if post.images|length %}
                    <img src="{{ post.images[0].url }}" alt="{{ post.caption }}">
                {% endif %}
                {% if post.meta.isShort %}
                    <span class="badge">Short</span>
                {% endif %}
                <span class="duration">{{ post.meta.durationFormatted }}</span>
            </a>
            <h3>{{ post.caption }}</h3>
            <p>{{ post.meta.viewCount|number_format }} views</p>
        </div>
    {% endfor %}

    {% if feed.nextCursor %}
        <a href="?after={{ feed.nextCursor }}">Load more</a>
    {% endif %}
{% endif %}
```

### Filtering

```twig
{% set videos = craft.socialStream.getStream({ provider: 'youtube', mediaType: 'VIDEO' }) %}
{% set shorts = craft.socialStream.getStream({ provider: 'youtube', mediaType: 'SHORT' }) %}
{% set all    = craft.socialStream.getStream({ provider: 'youtube' }) %}
```

### Embedding

```twig
{% if post.meta.embeddable %}
    <iframe
        src="{{ post.meta.embedUrl }}"
        width="560"
        height="315"
        frameborder="0"
        allowfullscreen></iframe>
{% else %}
    <a href="{{ post.permalink }}">Watch on YouTube</a>
{% endif %}
```

### Mixed-provider feed

Branch on `post.provider` — there's no interface boilerplate because both providers populate the same `Post` shape:

```twig
{% set ig = craft.socialStream.getStream({ provider: 'instagram', limit: 6 }) %}
{% set yt = craft.socialStream.getStream({ provider: 'youtube',   limit: 6 }) %}

{% for post in (ig.data|merge(yt.data))|sort((a, b) => b.timestamp.timestamp <=> a.timestamp.timestamp) %}
    {% if post.provider == 'youtube' and post.meta.embeddable %}
        <iframe src="{{ post.meta.embedUrl }}" width="560" height="315" allowfullscreen></iframe>
    {% else %}
        <a href="{{ post.permalink }}">
            {% if post.images|length %}
                <img src="{{ post.images[0].url }}" alt="{{ post.caption }}">
            {% endif %}
        </a>
    {% endif %}
{% endfor %}
```

### Profile

```twig
{% set profile = craft.socialStream.getProfile({ provider: 'youtube' }) %}
{% if profile.success %}
    <img src="{{ profile.data.thumbnailUrl }}" alt="{{ profile.data.title }}">
    <p>{{ profile.data.title }} — {{ profile.data.subscriberCount|number_format }} subscribers</p>
{% endif %}
```

---

## Quota optimisation strategy

10,000 units/day. Default cache TTLs + WebSub notifications keep real consumption low.

### Cost per operation

| Operation | Units | Notes |
|---|---|---|
| Get uploads playlist ID | 1 | Cached 24 hours — free on subsequent calls |
| List 50 playlist items | 1 | Per page |
| Get details for 50 videos | 1 | Batched |
| Get channel profile | 1 | Cached |
| 50 videos (cold miss) | ~3 | playlist items page + video details batch (+ 1 for uploads ID on very first miss) |
| 12 videos (cold miss) | ~2–3 | Same shape |

### Rules

1. Cache the uploads playlist ID for 24 hours.
2. Always batch `videos.list` at `maxResults=50`.
3. Use `maxResults=50` on `playlistItems.list` — 1 unit regardless of page size.
4. Rely on caching. With 1-hour feed TTL + WebSub, the API is hit infrequently.
5. **Never use `search.list`** — 100 units per call vs 1 for `playlistItems.list`.
6. Request only needed `part` values: `snippet,contentDetails,statistics,status` for videos, `snippet,statistics` for channels.

### Quota exhaustion

On HTTP 403 with `reason: quotaExceeded`:

1. Call `enterRateLimitCooldown()` with TTL = seconds until midnight Pacific Time.
2. Log a warning once.
3. Return stale cache or the standard error shape during cooldown.

---

## Implementation checklist

### Phase Y1: Infrastructure & token service

- [ ] Create migration `m{date}_add_youtube_fields`:
  - [ ] Add `refreshToken` TEXT NULL to `socialstream_connections`
  - [ ] Add `websubExpiresAt` DATETIME NULL to `socialstream_connections`
  - [ ] Do **not** add a channel ID column — reuse `providerUserId`
- [ ] Update `ConnectionRecord` docblock `@property` list with `refreshToken` and `websubExpiresAt`
- [ ] **TokenService — add `$provider` parameter to `exchangeAuthCode()`**
  - [ ] Change signature to `exchangeAuthCode(string $code, int $siteId, string $provider = 'instagram')`
  - [ ] Branch: Instagram keeps the existing short→long flow; YouTube POSTs to `https://oauth2.googleapis.com/token`
  - [ ] For YouTube: store `accessToken` + `refreshToken` (encrypted), `tokenExpiresAt`, and call `channels.list?mine=true` to populate `providerUserId`
- [ ] **TokenService — Google branch in `refreshToken()`**
  - [ ] `provider === 'youtube'` branch: POST to Google's token endpoint with `grant_type=refresh_token`
  - [ ] On `invalid_grant`: clear `accessToken` and `refreshToken`, set `lastError` to the Testing-mode guidance string
- [ ] **TokenService — auto-refresh in `getAccessToken()`**
  - [ ] For YouTube: check `tokenExpiresAt`, refresh inline if expired or within 5 minutes of expiry
  - [ ] Instagram path unchanged
- [ ] **TokenService — new helpers**
  - [ ] `getRefreshToken(int $siteId, string $provider): ?string`
- [ ] **CacheService — `excludeNonFeed` normalisation**
  - [ ] In `streamKey()`, force `excludeNonFeed` segment to `0` when `provider === 'youtube'`
- [ ] **Regression check**: Instagram OAuth + stream still works end-to-end after these changes

### Phase Y2: OAuth plumbing

- [ ] **AuthController — state encoding**
  - [ ] `actionHandleAuth()`: accept `provider` query param (default `'instagram'`); encode state as `base64(json_encode(['siteId' => …, 'provider' => …]))`
  - [ ] `actionCallback()`: decode state, handling both new JSON and legacy integer format for backward compatibility
  - [ ] Pass decoded `$provider` through to `TokenService::exchangeAuthCode()`
- [ ] **AuthController — Google OAuth initiation**
  - [ ] Build Google auth URL (scopes, `access_type=offline`, `prompt=consent`, redirect URI)
  - [ ] Reuse the single callback URL `/actions/social-stream/auth/callback`
- [ ] **AuthController — provider-aware post-exchange validation**
  - [ ] Instagram: keep existing `_validateAccountType()` call
  - [ ] YouTube: skip account-type validation; `exchangeAuthCode()` already populated the channel ID

### Phase Y3: YouTubeProvider

- [ ] Create `src/providers/YouTubeProvider.php` extending `base\Provider`
  - [ ] Static `handle()` returns `'youtube'`, `displayName()` returns `'YouTube'`
  - [ ] `doFetchProfile(int $siteId)`: `GET /channels?part=snippet,statistics&id={channelId}` → response shape
  - [ ] `doFetchStream(array $options)`: uploads playlist ID (cached 24h) → `playlistItems.list` → batch `videos.list` with `part=snippet,contentDetails,statistics,status` → map to `Post`
  - [ ] `mapToPost()`: populate `id`, `provider`, `caption` (← title), `permalink` (`/watch?v=…` or `/shorts/…`), `timestamp`, `likeCount`, `commentsCount`, `author`, `images` (single best thumbnail), `meta` (duration fields, viewCount, isShort, embeddable, madeForKids, thumbnails map, embedUrl, channelId, channelTitle, tags, definition, privacyStatus, mediaType), `raw`
  - [ ] `selectThumbnail()`: maxres → standard → high → medium → default fallback
  - [ ] `parseDuration()` via `DateInterval`
  - [ ] `formatDuration()` → `'1:30'` / `'2:15:03'`
  - [ ] `isShort()` heuristic (duration + `#shorts` hashtag)
  - [ ] Post-fetch filter on `mediaType`, over-fetch up to `maxFetchPages`
  - [ ] Return `nextPageToken` as `nextCursor`
  - [ ] Quota tracking: increment `social-stream:youtube-quota:{Y-m-d-PT}` on each API call
  - [ ] Quota exhaustion (`HTTP 403 quotaExceeded`): call `enterRateLimitCooldown()` with time-until-midnight-PT TTL
- [ ] Register in `SocialStream::_registerDefaultProviders()` alongside `InstagramProvider`
- [ ] **Regression check**: `craft.socialStream.getStream({ provider: 'youtube' })` returns a valid stream contract from Twig

### Phase Y4: WebSub

- [ ] `YouTubeProvider::subscribeWebSub(int $siteId): array` — POST to PubSubHubbub hub with channel feed topic URL; use `webhookVerifyToken` as `hub.secret`; update `websubExpiresAt` on success
- [ ] **WebhookController — `actionYoutubeVerify()`**
  - [ ] Read `hub.mode`, `hub.topic`, `hub.challenge`, `hub.lease_seconds`
  - [ ] Validate topic URL matches a known channel (lookup connection by `providerUserId`)
  - [ ] Return `hub.challenge` as plain text (404 on mismatch)
- [ ] **WebhookController — `actionYoutubeHandle()`**
  - [ ] Read raw body from `php://input`
  - [ ] Verify `X-Hub-Signature` (HMAC-SHA1)
  - [ ] Parse Atom XML for `<yt:channelId>` / `<yt:videoId>`
  - [ ] Look up connection by `providerUserId = channelId`, `provider = 'youtube'`
  - [ ] `RefreshStreamJob::pushIfNotQueued($siteId, [], 'youtube')`
  - [ ] Invalidate tag `social-stream:site-provider:{siteId}:youtube`
  - [ ] Record webhook receipt; return 200
- [ ] `RenewWebSubJob`
  - [ ] Self-rescheduling (9-day delay on success, 1-hour retry on failure)
  - [ ] Update `websubExpiresAt` on success
- [ ] **Auto-subscribe** — after a successful YouTube OAuth callback, call `subscribeWebSub()` and push the initial `RenewWebSubJob` with a 9-day delay
- [ ] **Console command** `php craft social-stream/web-sub/renew`
  - [ ] Renew all YouTube connections (or `--site=N`)
  - [ ] Recommended cron: `0 3 */7 * *`

### Phase Y5: CP settings & health panel

- [ ] Settings template — YouTube section (Client ID, Client Secret, Connect/Disconnect buttons, channel display, WebSub status)
- [ ] Health panel — YouTube indicators per the table above (quota usage, WebSub state, refresh token presence, etc.)
- [ ] "Test Connection" handler for YouTube (calls `channels.list`)
- [ ] Translation strings for all new UI and error messages
- [ ] SettingsController — persist Google Client ID / Secret on the YouTube connection record (encrypted, shares the existing `appId`/`appSecret` columns)

### Phase Y6: Documentation & testing

- [ ] Update `README.md`:
  - [ ] YouTube setup (Google Cloud Console project, enable YouTube Data API v3, OAuth credentials)
  - [ ] **Prominent warning about Google Testing-mode 7-day refresh-token expiry**, with instructions to publish the app (branding + privacy policy URL)
  - [ ] YouTube template usage (videos, Shorts, filtering, embedding, mixed-provider feeds)
  - [ ] Quota guidance (including how to request an increase via Google Cloud Console)
  - [ ] WebSub setup notes
  - [ ] Updated cron recommendations (include `social-stream/web-sub/renew`)
  - [ ] Shorts heuristic limitations and future `maxShortDuration` config option
- [ ] Update `CHANGELOG.md`
- [ ] Unit tests:
  - [ ] `YouTubeProvider` mapping — raw API payload → `Post` + `PostMedia` + `meta` shape
  - [ ] Thumbnail fallback chain (maxres → default)
  - [ ] `parseDuration()` edge cases (`PT0S`, `PT59S`, `PT1H`, `PT2H15M3S`, `P1DT2H`, malformed strings)
  - [ ] `formatDuration()` — seconds → `'1:30'` / `'2:15:03'`
  - [ ] `isShort()` heuristic branches (boundaries at 60s and 180s; with/without `#shorts`)
  - [ ] WebSub signature verification (HMAC-SHA1, in contrast to Instagram's SHA256)
  - [ ] OAuth state encoding/decoding — JSON and legacy-integer backward compat
  - [ ] `CacheService::streamKey()` — YouTube normalises `excludeNonFeed` to `0`
  - [ ] `Post::toArray()` / `Post::fromArray()` round-trips a YouTube post losslessly (including `meta`)
- [ ] Manual testing:
  - [ ] Full Google OAuth flow with a real YouTube channel
  - [ ] Fetch regular videos and Shorts; verify classification
  - [ ] `mediaType` filter: `VIDEO` only, `SHORT` only, all
  - [ ] Pagination via `nextCursor`
  - [ ] Cache behaviour: hit / stale / miss
  - [ ] WebSub: subscription, verification, notification handling, self-rescheduling renewal
  - [ ] Quota meter in the health panel
  - [ ] "Test Connection" button
  - [ ] Multi-site: different YouTube channels per site
  - [ ] JSON API endpoint with `provider=youtube`
  - [ ] Alongside Instagram — both providers active on one site, profile caches don't collide
  - [ ] Google Testing-mode token expiry detection and the messaging shown in the CP
  - [ ] OAuth state backward compatibility (upgrade scenario with in-flight Instagram OAuth)

---

## Risks & open questions

1. **Shorts detection reliability.** The duration + `#shorts` heuristic will have false positives (short regular videos) and false negatives (Shorts 61–180s without the hashtag, which YouTube enabled in October 2024). Document clearly. Consider `maxShortDuration` config override in a follow-up. Watch [issuetracker.google.com#232112727](https://issuetracker.google.com/issues/232112727) for an official field.

2. **Quota limits.** 10,000 units/day is generous for typical usage but tight for multi-site installations with frequent cache misses. Document how to request an increase via Google Cloud Console.

3. **WebSub reliability.** YouTube's WebSub notifications are reliable for new uploads but may miss some updates. The scheduled `social-stream/refresh` cron remains the safety net. The self-rescheduling `RenewWebSubJob` and the `social-stream/web-sub/renew` cron provide redundant renewal mechanisms.

4. **Google consent screen review.** For first-party use (site owner connecting their own channel), the Google app only needs to be *published*, not fully verified. But until then, refresh tokens expire every 7 days. The plugin detects this and surfaces a clear error. Document prominently.

5. **`excludeNonFeed` parameter.** Instagram-specific; silently ignored by YouTube and normalised to `0` in cache keys to avoid redundant entries. Document in the template-usage section.

6. **Video playback.** Unlike Instagram (where `mediaUrl` is a direct file URL), YouTube only provides embed URLs — template authors use `<iframe>` with `post.meta.embedUrl`. Branching on `post.provider` is clean because both providers populate the same `Post` shape. Document in the template-usage section.
