# Social Stream for Craft CMS

A Craft CMS 5 plugin for pulling Instagram posts and YouTube videos into your templates. Supports stream filtering, carousel children, Shorts detection, push notifications, caching with stale-while-revalidate, and multi-site configurations.

Both providers deliver the same `Post` shape to your templates, so a mixed feed needs no special handling beyond branching on `post.provider` where the two genuinely differ.

## Requirements

- Craft CMS 5.0 or later
- PHP 8.2 or later

For Instagram:

- An Instagram **Business** or **Creator** account linked to a Meta Business Suite page
- A Meta App with the Instagram product configured

For YouTube:

- A Google Cloud project with the **YouTube Data API v3** enabled and an API key

You need only the provider you intend to use — neither is required for the other.

## Installation

Install via Composer:

```bash
composer require enovate/social-stream
```

Then install the plugin via the Craft CP under **Settings > Plugins**, or from the command line:

```bash
php craft plugin/install social-stream
```

### Updating

Some releases include database migrations — 1.3.0 added a column to the connections table, and 1.4.0 adds four more for YouTube credentials and push-notification state. Run Craft's update command after pulling a new version:

```bash
php craft up
```

---

## Before you start

Instagram's authorisation has to happen on a publicly accessible URL, because its OAuth callback needs to reach your site. You can't complete it against a local domain unless you tunnel it out with [expose.dev](https://expose.dev/), `herd share`, or similar.

YouTube has no authorisation step — it uses an API key — but its push notifications do need a URL the hub can reach. Without one the feed still updates, just on the refresh cron rather than within seconds of an upload.

You don't need the Instagram credentials yourself, but someone who has them must be available for two steps:

- Under "3. Set up Instagram login", step 2, item 4 — to approve the Instagram tester role
- Everything under "4. Instagram login and Authorise" — essentially going through the Instagram OAuth flow from the Craft CMS control panel

## Instagram Setup

### 1. Create a Meta App

1. Go to [Meta for Developers](https://developers.facebook.com/apps/) and create a new app.
2. Give it a name (e.g. "My Site Social Stream") and enter the App contact email, then click "Next".
3. On the "Add use cases" screen, under "Filter by" select "Content management" then click "Manage messaging & content on Instagram", then click "Next".
4. On the "Which business portfolio do you want to connect to this app?" screen select the last option for "I don't want to connect a business portfolio yet", then click "Next".
5. On the "Publishing requirements" screen click "Next".
6. On the "Overview" screen, click "Create App".

### 2. Customise the app's permissions

1. Click on the pencil icon from the menu on the left to get to the "Use cases" screen for your app.
2. You should see "Manage messaging & content on Instagram", click on the "Customize" button next to it.
3. On the "Customize use case" screen click on "Permissions and features", then click "+ Add" next to "instagram_business_basic".
4. Then click Actions > Remove for both "instagram_business_manage_messages" and "instagram_manage_comments". The plugin doesn't use these permissions, and removing them avoids triggering Meta's App Review requirement for them.

### 3. Set up Instagram login

1. Click on "API setup with Instagram login", then note your **Instagram App ID** and **Instagram App Secret**.
2. Click on the "Roles" link (under "2. Generate access tokens"), which will take you off to the App roles screen in a new browser tab, where...
    1. Click on the "Add People" button in the top right.
    2. Select "Instagram Tester" under "Additional roles for this app".
    3. Enter the Instagram account username into the search field and select the account, click the "Add" button.
    4. Then log in to that Instagram account and go to https://www.instagram.com/accounts/manage_access/ where you will need to approve the Instagram tester role.
    5. Return to the previous browser tab.
3. Back under "2. Generate access tokens", you don't need to click "Add account" or copy a token — the plugin handles the token exchange when you click **Authorise** later.
4. Under "4. Set up Instagram business login" click on the "Setup" button, add the following URL to the **OAuth redirect URIs** field: `https://your-site.com/actions/social-stream/auth/callback`. Replace "your-site.com" with your Craft installation's **primary site** domain (including "www." if your site uses it) — the plugin always uses the primary site's base URL for the callback, even on multi-site installs.

The value you enter into the **OAuth redirect URIs** field should be a publicly accessible URL as this is where the callback from Instagram will land. You can set it up with a public staging URL and then add a production callback URL later. URLs can be added/removed at Meta under "4. Set up Instagram business login" by clicking on the "Business login settings" button.

### 4. Instagram login and Authorise

**Please note:**

- The next steps need to be performed by someone who has both the "Access Social Stream" permission in Craft CMS and the Instagram account login.
- You can use environment variables for your **Instagram App ID** and **Instagram App Secret**, if so set those up now.
- These steps must be performed in the environment whose domain you entered in the **OAuth redirect URIs** field earlier.
- The plugin exchanges the authorisation code for a long-lived token (60-day validity) and stores it encrypted in the database. A masked preview of the token and its expiry date are shown in the Connection Status panel.

1. Log in to the Instagram account first.
2. In Craft CMS navigate to "Social Stream" from the left hand menu
3. Open the **Instagram** row from the Providers tab and enter your **Instagram App ID** and **Instagram App Secret** (or your environment variable names if you set them up), then click "Authorise".
4. You'll be taken to Instagram to approve the connection, returning you to the plugin's Instagram page, where the Connection Status panel should now show **Status: Connected**.

With that done the connection is set up. You may want to review the settings on the "Configuration" tab, and on the "Stream Preview" tab click on "Load Stream Preview".

## Meta App Review

You can use the app in **Development Mode** with your own Instagram account added as a test user, this seems to work just fine.

---

## YouTube Setup

YouTube reads a public channel with an API key. There is no OAuth app to create, no consent screen to configure, no redirect URI to register and nothing that expires — but also no access to private or unlisted videos, which no API key can see.

### 1. Create a Google Cloud project and enable the API

1. Go to the [Google Cloud Console](https://console.cloud.google.com/) and create a project (e.g. "My Site Social Stream").
2. Under **APIs & Services > Library**, find **YouTube Data API v3** and click **Enable**.

No billing account is needed. The API is free within its daily quota of 10,000 units, which this plugin stays well inside — see [Quota](#quota).

### 2. Create an API key

1. Under **APIs & Services > Credentials**, click **Create credentials > API key**.
2. Copy the key.
3. Click **Edit API key** and restrict it, which takes a minute and is worth doing — an unrestricted key can be spent by anyone who obtains it:
   - **Application restrictions** → **IP addresses**, and add your server's outbound IP. Every call this plugin makes is server-side, so no browser ever needs the key. (Don't use **HTTP referrers**: that restriction is for keys used from a browser and will reject server-side calls.)
   - **API restrictions** → **Restrict key** → **YouTube Data API v3**.

### 3. Enter the key and channel in Craft

1. In Craft, go to **Social Stream**, press **Connect** on the YouTube row.
2. Paste the key into **API Key**. It is stored encrypted, and it can be the name of an environment variable — `$YOUTUBE_API_KEY` — if you'd rather keep it in `.env`.
3. Put the channel in **Channel**. Open the channel on YouTube and copy what's in the address bar; all of these work:

   | What you paste | Example |
   |---|---|
   | The channel's handle URL | `https://www.youtube.com/@EssexWebDevelopers` |
   | Just the handle | `@EssexWebDevelopers` |
   | A channel ID URL | `https://www.youtube.com/channel/UCuAXFkgsw1L7xaCfnd5JJOw` |
   | Just the channel ID | `UCuAXFkgsw1L7xaCfnd5JJOw` |
   | A legacy username URL | `https://www.youtube.com/user/SomeName` |
   | A link to any video on the channel | `https://www.youtube.com/watch?v=aqz-KE-bpKQ` |

4. Save. The channel is resolved on the spot and the Connection Status panel appears, showing the channel name, avatar and subscriber count.

**Check the channel it found is yours.** A mistyped handle is very often a *valid* handle belonging to somebody else, and the plugin cannot tell the difference — the panel is there so a wrong one is caught immediately rather than when the site fills with a stranger's uploads.

The old `youtube.com/c/SomeName` URLs are the one awkward case: there is no API parameter that resolves them. The plugin tries the name as a handle, which usually works because Google's auto-assigned handles tend to match the old custom name, but if it fails, open the channel and copy the `@handle` or `/channel/UC…` URL instead.

### What gets stored

The channel is resolved once, to its canonical `UC…` channel ID, and that ID is what every later request uses. Handles are deliberately not used as the durable identifier: a handle can be changed by its owner and later claimed by someone else, so a site that looked one up on every fetch could quietly start reading a different channel. Renaming your channel or its handle will not break the feed.

The text you typed is kept alongside it, so the field shows back what you entered rather than an ID you have never seen.

### Private and unlisted videos

Not supported, and not fixable with a setting: an API key can only read what a logged-out visitor can read. If a channel's uploads need to be visible to the plugin before they are public to everyone, YouTube requires OAuth as the channel's owner — which means a Google Cloud OAuth app, a consent screen, a published (or Internal) app and a credential that has to be reconnected when Google rejects it. That was the previous design, and it was removed in 1.4.0: the setup cost fell on every site that installed the plugin, while private videos were needed by almost none of them.

### Push notifications (WebSub)

After a successful connection the plugin subscribes to the channel's Atom feed through Google's PubSubHubbub hub, so a new upload triggers a background refresh within seconds instead of waiting for the cache to expire.

- The hub delivers to `/actions/social-stream/webhook/youtube` on your primary site's URL. Nothing to configure — it is registered at subscribe time.
- Notifications are authenticated with an HMAC-SHA1 signature over a per-connection secret. Unsigned or mismatched notifications are rejected.
- A subscription lease lasts at most **10 days**. A self-rescheduling queue job renews it every 9 days, and `php craft social-stream/web-sub/renew` does the same from cron — see [Cron Setup](#cron-setup). Both are idempotent, so running both is safe.
- Push notifications are an optimisation, never the only path: the scheduled refresh keeps the feed current if the hub goes quiet. A failed subscription therefore doesn't fail the connection; it shows in the **Push Notifications** row.

---

## Configuration

### CP Settings

Navigate to **Social Stream** in the CP sidebar.

#### Providers Tab

The landing tab lists every registered provider with its status, the connected account, and when it last fetched successfully. Each row has a button — **Connect** for a provider that isn't set up, **Configure** for one that is — leading to that provider's own page.

Providers get a page each rather than a tab each, so the screen doesn't grow a tab every time another one is registered. A provider added by a different plugin appears in this table too, with a working status and page.

Each provider's page holds its credentials, its authorisation button, and a health panel once connected:

| Provider | Credentials |
|---|---|
| Instagram | **Instagram App ID** and **App Secret**, from the Meta Developer portal |
| YouTube | An **API Key** from a Google Cloud project with the YouTube Data API v3 enabled, plus the **Channel** to read |

Both accept `$ENV_VAR` syntax. Instagram's page shows the exact redirect URI to register with Meta; YouTube has no redirect URI, because it has no authorisation step. Configure only the providers you use — nothing is required.

#### Configuration Tab

These apply to every provider.

| Setting | Description | Default |
|---|---|---|
| Default Post Limit | Number of posts to return when a template doesn't specify one (1-100). | 25 |
| Exclude Non-Feed Posts | Exclude posts not shared to the main feed (e.g. Reels-only). | Off |
| Cache Duration | How long to cache stream data, in minutes. | 60 |

#### API Tab

| Setting | Description | Default |
|---|---|---|
| Secure API Endpoint | Enable the optional JSON API endpoint. | Off |

When enabled, a **Generate Token** button creates a bearer token for API access. The token is shown once and cannot be retrieved later.

### Config File Overrides

All CP settings can be overridden via `config/social-stream.php`:

```php
<?php

return [
    'defaultLimit' => 12,
    'cacheDuration' => 120,
    'excludeNonFeed' => true,
    'secureApiEndpoint' => false,
    'maxFetchPages' => 5,
    'fetchPageSize' => 25,
    'shortsDetection' => true,
    'shortsRedirectFallback' => true,
    'shortsLookupBudget' => 8,
];
```

| Key | Type | Default | Description |
|---|---|---|---|
| `defaultLimit` | `int` | `25` | Default number of posts to return when a template doesn't specify one (1-100) |
| `excludeNonFeed` | `bool` | `false` | Exclude posts where `is_shared_to_feed` is false |
| `cacheDuration` | `int` | `60` | Cache TTL in minutes |
| `secureApiEndpoint` | `bool` | `false` | Enable the JSON API endpoint |
| `maxFetchPages` | `int` | `3` | Max API pages to fetch when filtering reduces results |
| `fetchPageSize` | `int` | `25` | Items requested per API page when filtering is active (1-100). Instagram only |
| `shortsDetection` | `bool` | `true` | Whether to identify YouTube Shorts. With this off, every video is reported as `VIDEO` |
| `shortsRedirectFallback` | `bool` | `true` | Whether to fall back to a `/shorts/` page request for videos oEmbed can't classify |
| `shortsLookupBudget` | `int` | `8` | Seconds a single fetch may spend on Shorts lookups before leaving the rest for the next one |

#### Filtering and `limit`

`excludeNonFeed` and `mediaType` are applied by the plugin, not by Instagram — the API has no way to filter on them. The plugin therefore over-fetches: it requests `fetchPageSize` items per page and keeps paging, up to `maxFetchPages`, until it has collected `limit` posts that survive the filter.

This matters most when `limit` is small. Asking for 3 posts does **not** mean only 3 posts are examined; a full page is fetched and filtered down. Without that, a template asking for 3 posts from an account where most posts are filtered out would render one or two tiles, or none at all.

If a filtered stream is still returning fewer posts than you asked for, the account has fewer matching posts than `maxFetchPages × fetchPageSize` reaches back. Raise `fetchPageSize` first — it costs the same number of API calls — then `maxFetchPages`.

---

## Template Usage

### Fetching the Stream

```twig
{% set stream = craft.socialStream.getStream({
    provider: 'instagram',
    limit: 12,
    mediaType: 'IMAGE',
    excludeNonFeed: true,
    siteId: currentSite.id,
}) %}

{% if stream.success %}
    {% for post in stream.data|filter(post => post.hasMedia()) %}
        <a href="{{ post.permalink }}">
            <img src="{{ post.images[0].url }}" alt="{{ post.caption }}">
        </a>
    {% endfor %}
{% else %}
    <p>Instagram feed is temporarily unavailable.</p>
{% endif %}
```

A post can arrive with nothing renderable attached — Instagram omits media URLs from posts it considers copyright-encumbered, and a carousel's children can fail to fetch. Filtering on `hasMedia()` keeps `images[0]` safe to index; without it, one such post is a fatal `Key "0" does not exist` error on the whole page.

### Parameters

| Parameter | Type | Default | Description |
|---|---|---|---|
| `provider` | `string` | **Required** | Which provider to fetch from: `'instagram'` or `'youtube'` |
| `limit` | `int` | CP setting | Number of posts to return |
| `mediaType` | `string\|null` | `null` (all) | Filter. Instagram: `IMAGE`, `VIDEO`, `CAROUSEL_ALBUM`. YouTube: `VIDEO`, `SHORT` |
| `excludeNonFeed` | `bool` | CP setting | Exclude posts where `is_shared_to_feed` is false. **Instagram only** — YouTube has no equivalent and ignores it |
| `siteId` | `int` | Current site | Which site's connection to use |
| `after` | `string\|null` | `null` | Pagination cursor from a previous response's `nextCursor` |

An unrecognised `mediaType` for a provider is ignored with a warning in the log rather than filtering the feed down to nothing.

### Response Contract

Every call to `getStream()` returns a consistent object:

| Property | Type | Description |
|---|---|---|
| `success` | `bool` | Whether the fetch succeeded |
| `data` | `Post[]` | Array of `Post` objects (empty on failure) |
| `nextCursor` | `string\|null` | Cursor for the next page |
| `error` | `string\|null` | Error message (null on success) |
| `cached` | `bool` | Whether served from cache |

An API failure always produces `success: false` with the provider's message in `error`. A failure part-way through pagination fails the whole fetch: posts already collected are discarded rather than returned as a shorter success, since a truncated stream cached for the full TTL is indistinguishable from a healthy one. Where a previous response is still inside its stale window, that is served instead.

Failed responses are never cached *as responses*, but the failure itself is remembered for 5 minutes: during that window requests return the same error without another API call, so a sustained upstream outage costs one call per 5 minutes rather than one per page view. Recovery is automatic once the window passes.

### Post Properties

Each `Post` object in `stream.data` provides:

| Property | Type | Description |
|---|---|---|
| `id` | `string` | Provider-native post ID |
| `provider` | `string` | Provider handle that produced this post (e.g. `'instagram'`) |
| `caption` | `string\|null` | Post caption or title |
| `permalink` | `string\|null` | URL of the post on the provider |
| `timestamp` | `DateTime\|null` | Post publish time |
| `likeCount` | `int\|null` | Number of likes |
| `commentsCount` | `int\|null` | Number of comments |
| `author` | `PostAuthor\|null` | Author of the post — see below |
| `images` | `PostMedia[]` | Image attachments — see below |
| `videos` | `PostMedia[]` | Video attachments — see below |
| `children` | `Post[]` | Carousel children (empty for non-carousels) |
| `meta` | `array` | Provider-specific extras (e.g. `isSharedToFeed`, `mediaProductType`, `shortcode`) |
| `raw` | `array` | Untransformed API response — escape hatch for debugging |

`Post` also exposes a `hasMedia()` method — true when the post has an image, a video, or a carousel child that has one. Use it to skip posts with nothing to render, as in the example above.

`PostMedia` exposes `type` (`'image'` or `'video'`), `url`, `thumbnailUrl`, `width`, `height`.

Instagram omits `media_url` from a video's response when the media contains copyrighted content — typically a reel with licensed audio, and it can start happening to a post long after it was published. Those posts arrive with an empty `videos` array and their thumbnail in `images` instead, so they still render as a still that links out to `permalink`, which is where the video plays anyway. Check `videos|length` before reaching for a playable URL rather than assuming `meta.mediaType == 'VIDEO'` guarantees one.

`PostAuthor` exposes `id`, `name`, `handle`, `url`, `avatarUrl`. For Instagram, only `id` and `handle` are populated from the stream response — call `craft.socialStream.getProfile()` for richer account data (username, profile picture, follower count). For YouTube, `id` is the channel ID, `name` and `handle` the channel title, and `url` the channel page.

#### `meta` by provider

`meta` carries everything provider-specific. Instagram populates `mediaType`, `isSharedToFeed`, `mediaProductType` and `shortcode`. YouTube populates:

| Key | Type | Description |
|---|---|---|
| `title` | `string\|null` | Video title — the same value as `caption` |
| `description` | `string\|null` | Full video description |
| `duration` | `string\|null` | Raw ISO 8601 duration, e.g. `PT1M30S` |
| `durationSeconds` | `int` | Duration in whole seconds |
| `durationFormatted` | `string` | Clock duration, e.g. `1:30` or `2:15:03` |
| `definition` | `string\|null` | `hd` or `sd` |
| `viewCount` | `int\|null` | View count, or null when the channel hides statistics |
| `tags` | `array` | Video tags |
| `categoryId` | `string\|null` | YouTube category ID |
| `privacyStatus` | `string\|null` | `public`, `unlisted` or `private` |
| `embeddable` | `bool` | Whether an iframe embed will play — check this before rendering one |
| `madeForKids` | `bool` | Whether the video is marked as made for kids |
| `isShort` | `bool` | Whether the video is a Short |
| `mediaType` | `string` | `VIDEO` or `SHORT` — the same shape Instagram writes |
| `channelId` / `channelTitle` | `string\|null` | The publishing channel |
| `thumbnails` | `array` | The full size map (`default` through `maxres`) as the API returned it |
| `embedUrl` | `string\|null` | `https://www.youtube.com/embed/{id}` |

YouTube exposes no direct video file URL, so a YouTube post always has an empty `videos` array and its best thumbnail in `images`. Playback is the iframe — see [YouTube Videos and Shorts](#youtube-videos-and-shorts).

### Carousel Rendering

```twig
{% for post in stream.data %}
    {% if post.children|length %}
        <div class="carousel">
            {% for child in post.children %}
                {% if child.videos|length %}
                    <video src="{{ child.videos[0].url }}" poster="{{ child.videos[0].thumbnailUrl }}" controls></video>
                {% elseif child.images|length %}
                    <img src="{{ child.images[0].url }}" alt="">
                {% endif %}
            {% endfor %}
        </div>
    {% elseif post.videos|length %}
        <video src="{{ post.videos[0].url }}" poster="{{ post.videos[0].thumbnailUrl }}" controls></video>
    {% elseif post.images|length %}
        <img src="{{ post.images[0].url }}" alt="{{ post.caption }}">
    {% endif %}
{% endfor %}
```

### Pagination

```twig
{% set cursor = craft.app.request.getQueryParam('after') %}
{% set stream = craft.socialStream.getStream({
    provider: 'instagram',
    limit: 6,
    after: cursor,
}) %}

{% if stream.success %}
    {% for post in stream.data %}
        {# render posts #}
    {% endfor %}

    {% if stream.nextCursor %}
        <a href="{{ url(craft.app.request.pathInfo, { after: stream.nextCursor }) }}">
            Load more
        </a>
    {% endif %}
{% endif %}
```

### Profile Information

```twig
{% set profile = craft.socialStream.getProfile({ provider: 'instagram' }) %}

{% if profile.success %}
    <p>{{ profile.data.username }} — {{ profile.data.followers_count }} followers</p>
{% endif %}
```

A YouTube profile returns the channel:

```twig
{% set profile = craft.socialStream.getProfile({ provider: 'youtube' }) %}

{% if profile.success %}
    <img src="{{ profile.data.thumbnailUrl }}" alt="{{ profile.data.title }}">
    <p>{{ profile.data.title }} — {{ profile.data.subscriberCount|number_format }} subscribers</p>
{% endif %}
```

Keys: `id`, `title`, `description`, `customUrl` (the `@handle`), `thumbnailUrl`, `subscriberCount`, `hiddenSubscriberCount`, `videoCount`, `viewCount`. A channel that hides its subscriber count returns `null` for `subscriberCount` with `hiddenSubscriberCount` true — check it before rendering the number.

### YouTube Videos and Shorts

```twig
{% set feed = craft.socialStream.getStream({ provider: 'youtube', limit: 6 }) %}

{% if feed.success %}
    {% for post in feed.data %}
        <div class="video-card">
            <a href="{{ post.permalink }}" target="_blank" rel="noopener">
                {% if post.images|length %}
                    <img src="{{ post.images[0].url }}" alt="{{ post.caption }}">
                {% endif %}
                {% if post.meta.isShort %}<span class="badge">Short</span>{% endif %}
                <span class="duration">{{ post.meta.durationFormatted }}</span>
            </a>
            <h3>{{ post.caption }}</h3>
            {% if post.meta.viewCount is not null %}
                <p>{{ post.meta.viewCount|number_format }} views</p>
            {% endif %}
        </div>
    {% endfor %}
{% endif %}
```

Filtering works the same way as Instagram's, with YouTube's two types:

```twig
{% set videos = craft.socialStream.getStream({ provider: 'youtube', mediaType: 'VIDEO' }) %}
{% set shorts = craft.socialStream.getStream({ provider: 'youtube', mediaType: 'SHORT' }) %}
{% set all    = craft.socialStream.getStream({ provider: 'youtube' }) %}
```

To play a video, embed it — `post.videos` is always empty, because the API offers no file URL:

```twig
{% if post.meta.embeddable %}
    <iframe src="{{ post.meta.embedUrl }}" width="560" height="315"
            frameborder="0" allowfullscreen loading="lazy"></iframe>
{% else %}
    <a href="{{ post.permalink }}">Watch on YouTube</a>
{% endif %}
```

#### How Shorts are detected

The Data API has no field saying whether a video is a Short ([issuetracker #232112727](https://issuetracker.google.com/issues/232112727)), so the plugin infers it from youtube.com. This is worth knowing about because it makes outbound requests your site would not otherwise make:

- On first sight of a video, the plugin asks YouTube's **oEmbed** endpoint about it at the `/shorts/` URL. Portrait dimensions come back for a Short, landscape for anything else. No API key, no quota cost.
- For videos oEmbed refuses — private ones, and those with embedding disabled — it falls back to requesting the `/shorts/` page with redirects off: 200 means a Short, a redirect to `/watch` means a regular video.
- Each answer is cached **permanently and per video**: a video's Shorts status cannot change. Warm fetches make no outbound requests at all, and a new upload doesn't re-check the rest of the feed.
- A lookup that fails or times out is **not** cached. The video is reported as `VIDEO` for that fetch and retried on the next one — so a network blip can't permanently mislabel a Short.
- Lookups are concurrent and bounded by `shortsLookupBudget` (8 seconds by default). A cold fetch of a 50-video page that exceeds it leaves the remainder unresolved for the background refresh to pick up.

Duration is not used for this: the Shorts limit is now 3 minutes, so length can't separate a 90-second Short from a 90-second video. Set `shortsDetection` to `false` in config to skip the lookups entirely, at the cost of every video reporting `mediaType: 'VIDEO'`.

### Mixed-Provider Feeds

Both providers populate the same `Post` shape, so interleaving them needs no adapter — only a branch where rendering genuinely differs:

```twig
{% set ig = craft.socialStream.getStream({ provider: 'instagram', limit: 6 }) %}
{% set yt = craft.socialStream.getStream({ provider: 'youtube',   limit: 6 }) %}

{% set posts = (ig.data|merge(yt.data))|sort((a, b) => b.timestamp.timestamp <=> a.timestamp.timestamp) %}

{% for post in posts|filter(post => post.hasMedia()) %}
    <a href="{{ post.permalink }}" target="_blank" rel="noopener">
        <img src="{{ post.images[0].url }}" alt="{{ post.caption }}">
        {% if post.provider == 'youtube' %}
            <span class="duration">{{ post.meta.durationFormatted }}</span>
        {% endif %}
    </a>
{% endfor %}
```

Each provider is cached and rate-limited independently, so one being unavailable doesn't take the other down — check `success` per call rather than assuming both succeeded.

---

## Cron Setup

A single cron entry handles both stream cache pre-warming and Instagram token refresh:

```cron
# Social Stream — pre-warms the cache and refreshes expiring tokens
7,37 * * * * cd /path/to/craft && php craft social-stream/refresh
```

Each run pushes a `RefreshStreamJob` per connection, and additionally queues a `RefreshTokenJob` for any connection whose Instagram token is within 7 days of expiry. No separate daily cron for token refresh is needed — it's handled opportunistically.

YouTube connections are skipped by that second step entirely: an API key doesn't expire, so there is nothing to refresh. The stream pre-warm still runs for them.

Instagram token refresh happens **only** on this path, so a cron that silently never runs will let a token expire with nothing else to signal it. Verify yours actually fires — `cron` uses a minimal `PATH`, so an unqualified `php` that works in your shell may not resolve there.

`RefreshTokenJob` retries on a 1 → 5 → 30 minute backoff and then fails the job, so an unrecoverable refresh is visible in the CP's Queue Manager. A credential the provider has rejected outright is the exception: retrying it is pointless, so the job stops immediately and the cron skips the connection from then on, reporting it in the command output. The signal in that case is the CP banner — see [Instagram has rejected this token](#instagram-has-rejected-this-token).

**Cadence:** set this to roughly half of your configured `cacheDuration` (default: 60 minutes → every 30 minutes). That gives one pre-warm per fresh window plus a safety margin if a cron run is missed.

**Pick random minute offsets.** The example above uses `7,37` rather than `0,30` or `*/30`. Running exactly on the hour means every Social Stream install hits Meta's API at the same instant, which strains their rate limits and slows your own requests. Choose any two minute values 30 apart that suit your infrastructure.

Options accepted by `social-stream/refresh`:

- `--site=<id>` — scope to a single site (otherwise all sites with a connection are refreshed)
- `--provider=<handle>` — scope to a single provider (`instagram` or `youtube`)
- `--force-token` — queue a token refresh for every matched connection regardless of expiry

### YouTube push notification renewal

If you use YouTube, add a second entry to keep its push subscription alive:

```cron
# Social Stream — renews YouTube push notification leases
0 3 * * * cd /path/to/craft && php craft social-stream/web-sub/renew
```

A WebSub lease lasts at most 10 days, and the hub stops delivering when it lapses. The command only re-subscribes connections whose lease expires within the next 3 days, so it is cheap to run daily and leaves a week of margin if a day is missed. It costs no API quota — the hub is not part of the Data API.

Options: `--site=<id>`, `--within=<days>` (default 3), `--force` (re-subscribe regardless of the lease).

This cron is optional. A self-rescheduling queue job renews the lease every 9 days on its own, which covers sites with no cron configured; both mechanisms are idempotent, and re-subscribing simply resets the lease. The cron is the more reliable of the two, because it doesn't depend on the queue being drained. And if both lapse, the feed still updates — it falls back to the cache expiring and the refresh cron.

### Running on multiple web hosts

The cron command is safe to run on every web host in a load-balanced setup. Before pushing either kind of job, the plugin checks the Craft queue table (via the primary database connection, so read-replica lag can't mislead it) and skips the push if an identical pending job is present or if an identical job failed within the last two hours. No server-affinity or cron-on-one-host-only configuration is required — though you're free to run cron on a single host if you prefer.

### Manual token refresh

The consolidated cron handles token refresh automatically. You only need to run the manual command after re-authenticating or if you want to force-refresh a token early:

```bash
php craft social-stream/token/refresh                      # every provider, every site
php craft social-stream/token/refresh --site=1              # a specific site
php craft social-stream/token/refresh --provider=instagram  # a specific provider
```

This command uses the same queue-table dedup as the cron, so it's also safe on multiple hosts.

---

## JSON API Endpoint

An optional JSON API is available at `/actions/social-stream/api` for external consumers (e.g. JavaScript front-ends, mobile apps).

### Enabling

1. Toggle **Secure API Endpoint** to on in the **API** tab.
2. Click **Generate Token** to create a bearer token.
3. **Copy the token immediately** — it is shown once and cannot be retrieved later.

### Usage

```bash
curl -H "Authorization: Bearer YOUR_TOKEN" \
  "https://your-craft-site.com/actions/social-stream/api?provider=instagram&limit=12&mediaType=IMAGE"
```

Query parameters: `provider` (**required**), `limit`, `mediaType`, `excludeNonFeed`, `after`, `siteId`.

The response matches the same contract as `craft.socialStream.getStream()`.

---

## Connection Health Panel

When a provider is connected, its page displays a health panel. For Instagram:

- **Token status** — green (valid), amber (expiring within 7 days), red (expired, or rejected by the provider)
- **Token expiry date**
- **Last successful fetch** timestamp
- **Last error** message and timestamp
- **Rate-limit cooldown** — active or inactive
- **API version** in use

Two action buttons are available:

- **Test Connection** — makes a `GET /me` call and displays the account name and type. This is a live call even when the connection is flagged as needing re-authorisation, so using it clears the flag on a connection that has recovered. It deliberately leaves **Last successful fetch** and **Last error** alone: it fetches no posts, so it has nothing to say about the stream.
- **Refresh Stream Now** — queues a background stream refresh immediately

The YouTube panel reports the same stream diagnostics plus what is specific to it:

- **Status** — green once a channel has been resolved. There is no credential expiry to report: an API key doesn't have one
- **Channel** — name, `@handle` and subscriber count, from the cached profile response. Opening the settings page never spends API quota, so these appear once something has fetched the profile — pressing **Test Connection** is the quickest way
- **Channel ID** — the `UC…` identifier every request uses, and what push notifications are routed by
- **Push Notifications** — the WebSub lease state and when a notification last arrived. "Not subscribed" is a degraded state, not a broken one: uploads then appear when the cache expires
- **Daily quota** — units spent today against the 10,000 limit, resetting at midnight Pacific Time. It is a local count for diagnosis, not Google's ledger — the Cloud Console is authoritative
- **Test Connection**, **Refresh Stream Now** and **Disconnect**. Disconnecting unsubscribes from the hub and forgets the tokens and channel, keeping the client ID and secret so the same channel can be reconnected in one click

**Last successful fetch** is only stamped by a stream fetch that actually returned posts — not by cache hits, failed requests, or a profile call — so a stale timestamp is meaningful rather than merely quiet. **Last error** persists until a stream fetch succeeds.

---

## Multi-Site Support

Each Craft site can connect a different Instagram account and a different YouTube channel, with independent settings. Use the site switcher at the top of the settings page to configure each site.

Two sites may point at the same YouTube channel; a push notification for it refreshes both.

In templates, the `siteId` parameter defaults to the current site. To explicitly request a different site's stream:

```twig
{% set stream = craft.socialStream.getStream({ provider: 'instagram', siteId: 2 }) %}
```

---

## Caching

The plugin caches stream responses using Craft's cache component (respects your configured driver: file, Redis, Memcached, etc.).

- **Cache duration** is configurable per site (default: 60 minutes).
- **Stale-while-revalidate**: expired cache data is served immediately while a background job refreshes the content.
- **Stampede protection**: mutex locks prevent multiple simultaneous API calls when the cache expires.
- **Failure backoff**: a failed fetch is remembered for 5 minutes and replayed from that memory, so an upstream outage can't turn every uncached request into a live API call. Failed responses themselves are never cached as content.
- **Cache clearing**: use **Utilities > Caches > Invalidate data caches > Social Stream data** in the CP, or run `php craft invalidate-tags/social-stream` from the CLI.

---

## Troubleshooting

### "Insufficient developer role" during authorisation

If the OAuth flow lands on a Meta error page reading **"Insufficient developer role"** (URL contains `instagram.com/oauth/authorize/third_party/error/`), the browser is logged into an Instagram account that hasn't been added as a tester on the Meta App.

Before clicking **Authorise** (or **Re-authorise**), make sure the browser is logged into the **same Instagram account** that was added as an Instagram tester in step 3.2 of the setup — and that the tester invite has been accepted at [instagram.com/accounts/manage_access/](https://www.instagram.com/accounts/manage_access/).

The cleanest way to be certain:

1. Open an incognito/private window.
2. Go to [instagram.com](https://www.instagram.com/) and log in with the account you want to connect.
3. Return to the plugin's **Instagram** page in the Craft CP and click **Authorise**.

This avoids any session confusion with personal Instagram accounts you may be signed into elsewhere.

### Token has expired

The token must be refreshed before its 60-day expiry. Set up the consolidated cron (`php craft social-stream/refresh`) to handle this automatically — it queues a token refresh once a token is within 7 days of expiring. You can also re-authorise from the plugin's **Instagram** page.

If a token expired anyway, check that the cron is genuinely running before looking anywhere else — a crontab entry that fails every time is silent. Simulate cron's stripped environment with `env -i /bin/sh -c '<your cron line>'`; if it fails there but works in your shell, use the absolute binary path from `which php` in the crontab.

The plugin logs every refresh outcome to `storage/logs/social-stream-*.log`, so `grep -iE 'token refresh|refreshed token'` is the fastest way to tell "the refresh is being rejected" from "the refresh never ran".

### Instagram has rejected this token

Distinct from an expired token: the provider returned OAuthException code 190, meaning the credential was expired, revoked, or invalidated (e.g. by an Instagram password change). Meta will not refresh a token in this state, so re-authorising from the plugin's **Instagram** page is the only route back.

While the connection is in this state the plugin suspends stream API calls rather than repeating a request it knows will fail, and the cron stops queueing token refreshes for it — Meta will not refresh a credential it has already refused.

The flag clears in three ways: re-authorising, a successful **Test Connection**, or the hourly probe. The probe lets a single stream request through every 60 minutes; if the rejection was transient and the credential works again, that request succeeds and the flag clears with no intervention. A genuinely dead token simply fails the probe, and suppression continues at a cost of one API call an hour.

### Wrong account type

The Instagram Graph API requires a **Business** or **Creator** account. Personal accounts are not supported. Convert your account in Instagram's settings under **Account > Switch to professional account**.

### Rate limited

If the Instagram API returns a rate-limit error (HTTP 429), the plugin enters a 15-minute cooldown. During this time, stale cached data is served instead of making API calls. The cooldown status is visible in the Connection Health panel.

### Missing fields

Some fields (e.g. `like_count`, `comments_count`) may not be returned depending on your app's permissions or the media type. The plugin defaults missing values to `null` gracefully. Ensure your Meta App has the required permissions approved.

### A video renders as a still image

Instagram omits `media_url` from a video's response when the media contains copyrighted content — most often a reel with licensed audio. It can start doing so long after the post was published, so a feed that has been working for months can change behaviour with no code change at either end.

There is nothing to fix on the Craft side: no URL is served, so the video cannot be embedded. The plugin falls back to the post's thumbnail, which Instagram still provides, so the post renders as a still. Link it to `post.permalink` and the video plays on Instagram, where the audio licence applies. The post is identifiable in a template as `post.meta.mediaType == 'VIDEO'` with an empty `post.videos` — enough to overlay a play badge if you want it to read as a video rather than a photo.

If Instagram withholds the thumbnail too, the post has nothing renderable at all and `hasMedia()` returns false, so filtering on it (see [Fetching the Stream](#fetching-the-stream)) skips the post instead of failing the page.

### YouTube rejected the API key

Google refuses the key outright. In order of likelihood: the key was restricted to **HTTP referrers** rather than IP addresses (every call the plugin makes is server-side, so a referrer restriction rejects all of them); the server's outbound IP isn't in the key's IP allowlist, or has changed; **API restrictions** don't include the YouTube Data API v3; or the key was deleted in the Cloud Console.

None of it recovers by itself and none of it is fixed by re-entering the same key — correct the restriction in the Cloud Console, or paste a new key on the plugin's **YouTube** page.

### YouTube is reading the wrong channel

Almost always a mistyped handle that happens to belong to somebody else. Check the **Channel** field against the channel name and avatar in the Connection Status panel, correct it, and save — the channel is re-resolved whenever the field changes.

### YouTube quota exhausted

The Data API allows **10,000 units per day per Google Cloud project**, resetting at midnight Pacific Time. A cold fetch of a page of videos costs about 3 units, so normal use is nowhere near it — but the quota is per project, so many sites sharing one API key share the allowance. Give each site its own Cloud project if that becomes a problem.

When Google returns `quotaExceeded`, the plugin suspends calls until the reset rather than retrying into the same wall every request, and serves stale cache in the meantime. The YouTube page shows the day's count.

If you genuinely need more, request an increase under **APIs & Services > YouTube Data API v3 > Quotas** in the Cloud Console. Before that, raise `cacheDuration`: the plugin never calls the API for a request it can serve from cache, and push notifications mean a longer TTL doesn't delay new uploads.

The plugin never uses `search.list`, which costs 100 units per call against the 1 that `playlistItems.list` costs — worth knowing if you write your own YouTube code alongside it.

### A Short is reported as a regular video

Shorts detection is an inference from youtube.com's behaviour, not an API field — see [How Shorts are detected](#how-shorts-are-detected). Two causes are worth checking:

- **The lookup didn't complete.** A failed or timed-out lookup is never cached, and the video is reported as `VIDEO` for that fetch only. Refresh the stream (or wait for the background refresh) and it resolves. A cold 50-video page can also run out of its `shortsLookupBudget`; raise it if that happens routinely.
- **Outbound requests are blocked.** The lookups go to `youtube.com` from your server. Behind an egress firewall that blocks them, every video will report as `VIDEO`. The log records how many videos went unresolved per fetch.

A misclassification that persists after a refresh — with outbound requests working — most likely means YouTube changed the behaviour being relied on. Set `shortsDetection` to `false` to stop the lookups until the plugin catches up.

### New YouTube uploads take an hour to appear

Push notifications aren't arriving, so the feed is waiting for the cache to expire. Check the **Push Notifications** row on the YouTube page:

- **Not subscribed** — the hub refused or was never asked. Run `php craft social-stream/web-sub/renew --force` and watch for errors.
- **Lease expired** — nothing renewed it. Either the queue isn't being drained (the self-rescheduling job never ran) or the renewal cron isn't set up. Add the cron — see [YouTube push notification renewal](#youtube-push-notification-renewal).
- **Active, but no recent notification** — the hub verified the subscription but deliveries aren't landing. The callback must be publicly reachable: confirm `/actions/social-stream/webhook/youtube` isn't behind basic auth, an IP allowlist, or a WAF rule. A rejected signature is logged, so check `storage/logs/social-stream-*.log` before suspecting the network.

The feed is never *stuck* in this state — the refresh cron and cache expiry keep it current. Push notifications only decide whether that takes seconds or up to an hour.

### Using the health panel

The Connection Health panel on each provider's page provides at-a-glance diagnostics:

- The Providers tab is the fastest read: a red or amber dot names the provider that needs attention, and the row carries the last error.
- A red token status means either the stored expiry has passed — re-authorise, and check your cron setup, since the refresh should have run 7 days earlier — or that Instagram has rejected the token outright, which only re-authorising fixes.
- A "Last Error" entry shows the most recent API failure. It's cleared by the next successful fetch, so an empty entry alongside a stale "Last successful fetch" is itself a signal.
- An active rate-limit cooldown means the API is temporarily suppressed.

Use the **Test Connection** button to verify the API is responding correctly.

---

## API Version

The plugin targets Instagram Graph API **v21.0** via `graph.instagram.com`. The version is centralised as a constant (`InstagramProvider::API_VERSION`) and displayed in the Connection Health panel.

YouTube uses **Data API v3** at `googleapis.com/youtube/v3`, which is unversioned beyond that path. Push notifications come from `pubsubhubbub.appspot.com`; Shorts detection talks to `youtube.com`. There are no Google OAuth endpoints in the plugin any more.

---

## Extending

### Registering a custom provider

Plugins and modules can register their own providers so `craft.socialStream.getStream({ provider: 'myprovider' })` works out of the box.

```php
use enovate\socialstream\services\Providers;
use craft\events\RegisterComponentTypesEvent;
use yii\base\Event;

Event::on(
    Providers::class,
    Providers::EVENT_REGISTER_PROVIDER_TYPES,
    function (RegisterComponentTypesEvent $event) {
        $event->types[] = MyProvider::class;
    }
);
```

Your provider should extend `enovate\socialstream\base\Provider`, implementing `handle()`, `doFetchStream()`, and `doFetchProfile()`. Optionally override `displayName()` to supply a human-readable name. The base class handles rate-limit state, error recording, last-fetch timestamps, and lifecycle events.

Two optional hooks are worth knowing about:

- `usesExcludeNonFeed()` — return `false` if the `excludeNonFeed` option means nothing to your provider, as YouTube's does. The option is then normalised out of your cache keys instead of splitting one stream across two identical entries. Defaults to `true`, which preserves the existing key shape for any provider that doesn't override it.
- `enterRateLimitCooldown($siteId, $ttl)` — pass a `$ttl` when your provider's limit is a daily quota rather than a rolling window, so calls are suppressed until it actually resets. Omit it for the default 15 minutes.

### Lifecycle events

Two events are emitted on every fetch. Use `EVENT_BEFORE_FETCH_STREAM` with `$event->handled = true` and `$event->result = [...]` to short-circuit the API call, or mutate `$event->result` in `EVENT_AFTER_FETCH_STREAM` to transform the response before it reaches the caller.

```php
use enovate\socialstream\base\Provider;
use enovate\socialstream\events\FetchStreamEvent;
use yii\base\Event;

Event::on(
    Provider::class,
    Provider::EVENT_AFTER_FETCH_STREAM,
    function (FetchStreamEvent $event) {
        // Only keep posts that mention a specific hashtag.
        if (!empty($event->result['data'])) {
            $event->result['data'] = array_filter(
                $event->result['data'],
                fn($post) => str_contains((string) $post->caption, '#featured'),
            );
        }
    }
);
```

### Background refresh event

`RefreshStreamJob::EVENT_AFTER_REFRESH_STREAM` fires after a background refresh successfully replaces the cached stream payload. The typical use case is invalidating a downstream cache (e.g. a CDN or Varnish fronting the page that renders the stream) so visitors see the new posts.

The event fires only when **all** of the following are true:

- The refresh ran via `RefreshStreamJob` — i.e. the cron entry point (`php craft social-stream/refresh`) or the queue. Cold-miss synchronous fetches on the front-end do **not** trigger it; those happen inside a single render and don't represent a change in upstream data.
- The provider's `fetchStream()` returned `success: true`. A failed fetch leaves the previous cache in place and is not signalled.
- The new payload differs from what was already cached. If the refresh produced a byte-identical response (same posts, same order, same engagement counts), nothing downstream needs to invalidate, so the event is suppressed.

The event is dispatched from `RefreshStreamJob`, so subscribers attach to that class:

```php
use enovate\socialstream\events\StreamRefreshedEvent;
use enovate\socialstream\jobs\RefreshStreamJob;
use yii\base\Event;

Event::on(
    RefreshStreamJob::class,
    RefreshStreamJob::EVENT_AFTER_REFRESH_STREAM,
    function (StreamRefreshedEvent $event) {
        // $event->siteId, $event->provider, $event->options, $event->response
        // ...purge a CDN, ping a webhook, etc.
    }
);
```

The event runs in the queue worker (console) context, not in a web request, so listeners that need a request URL should derive it from the site's base URL via `$event->siteId` rather than reading it from `Craft::$app->getRequest()`.
