# Social Stream for Craft CMS

A Craft CMS 5 plugin for pulling Instagram posts into your templates via the Instagram Graph API. Supports stream filtering, carousel children, caching with stale-while-revalidate, and multi-site configurations.

## Requirements

- Craft CMS 5.0 or later
- PHP 8.2 or later
- An Instagram **Business** or **Creator** account linked to a Meta Business Suite page
- A Meta App with the Instagram product configured

## Installation

Install via Composer:

```bash
composer require enovate/social-stream
```

Then install the plugin via the Craft CP under **Settings > Plugins**, or from the command line:

```bash
php craft plugin/install social-stream
```

---

## Meta App Setup

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
2. Click on the "Roles" link, which will take you off to the App roles screen in a new browser tab, where...
    1. Click on the "Add People" button in the top right.
    2. Select "Instagram tester" under "Additional roles for this app".
    3. Enter the Instagram account username into the search field and select the account, click the "Add" button.
    4. Then login to that Instagram account and go to https://www.instagram.com/accounts/manage_access/ where you will need to approve the tester role.
    5. Return to the previous browser tab.
3. Under "2. Generate access tokens" expand the section by clicking on the down chevron, then click "Add account".
4. Sign in with the Instagram account you want to connect and complete Meta's prompts. You don't need to copy any token — the plugin will handle the token exchange when you click **Authorise** in the Craft CP (next section).
5. Under "4. Set up Instagram business login" click on the "Setup" button, then step through the wizard and add the following URL to the **OAuth redirect URIs** field: `https://your-site.com/actions/social-stream/auth/callback`. Replace "your-site.com" with your Craft installation's **primary site** domain (including "www." if your site uses it) — the plugin always uses the primary site's base URL for the callback, even on multi-site installs.

## Plugin setup & quick start

**Please note:**

- You can use environment variables for your **Instagram App ID** and **Instagram App Secret**, if so set those up now.
- These steps are best followed in your production environment.
- The plugin exchanges the authorisation code for a long-lived token (60-day validity) and stores it encrypted in the database. A masked preview of the token and its expiry date are shown in the Connection Status panel.

1. In Craft CMS navigate to "Social Stream" from the left hand menu
2. On the "Connection" tab enter your **Instagram App ID** and **Instagram App Secret** (or your environment variable names if you set them up), then click "Authorise".
3. You'll need to login to the Instagram account and approve the connection.
4. Review the settings on the "Configuration" tab.
5. On the "Stream Preview" tab click on "Load Stream Preview".

## Meta App Review

You can use the app in **Development Mode** with your own Instagram account added as a test user, this seems to work just fine.

---

## Configuration

### CP Settings

Navigate to **Social Stream** in the CP sidebar. The settings page is organised into three tabs:

#### Connection Tab

| Setting | Description | Default |
|---|---|---|
| Instagram App ID | From the Meta Developer portal. Supports `$ENV_VAR` syntax. | — |
| Instagram App Secret | From the Meta Developer portal. Supports `$ENV_VAR` syntax. | — |

An **Authorise** button starts the OAuth flow to connect your Instagram account. Once connected, the Connection Health panel is displayed here (see below).

#### Configuration Tab

| Setting | Description | Default |
|---|---|---|
| Default Post Limit | Number of posts to fetch (1-100). | 25 |
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
];
```

| Key | Type | Default | Description |
|---|---|---|---|
| `defaultLimit` | `int` | `25` | Default number of posts to fetch (1-100) |
| `excludeNonFeed` | `bool` | `false` | Exclude posts where `is_shared_to_feed` is false |
| `cacheDuration` | `int` | `60` | Cache TTL in minutes |
| `secureApiEndpoint` | `bool` | `false` | Enable the JSON API endpoint |
| `maxFetchPages` | `int` | `3` | Max API pages to fetch when filtering reduces results |

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
    {% for post in stream.data %}
        <a href="{{ post.permalink }}">
            <img src="{{ post.images[0].url }}" alt="{{ post.caption }}">
        </a>
    {% endfor %}
{% else %}
    <p>Instagram feed is temporarily unavailable.</p>
{% endif %}
```

### Parameters

| Parameter | Type | Default | Description |
|---|---|---|---|
| `provider` | `string` | **Required** | Which provider to fetch from (e.g. `'instagram'`) |
| `limit` | `int` | CP setting | Number of posts to return |
| `mediaType` | `string\|null` | `null` (all) | Filter: `IMAGE`, `VIDEO`, `CAROUSEL_ALBUM` |
| `excludeNonFeed` | `bool` | CP setting | Exclude posts where `is_shared_to_feed` is false |
| `siteId` | `int` | Current site | Which site's connection to use |
| `after` | `string\|null` | `null` | Pagination cursor from a previous response's `nextCursor` |

### Response Contract

Every call to `getStream()` returns a consistent object:

| Property | Type | Description |
|---|---|---|
| `success` | `bool` | Whether the fetch succeeded |
| `data` | `Post[]` | Array of `Post` objects (empty on failure) |
| `nextCursor` | `string\|null` | Cursor for the next page |
| `error` | `string\|null` | Error message (null on success) |
| `cached` | `bool` | Whether served from cache |

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

`PostMedia` exposes `type` (`'image'` or `'video'`), `url`, `thumbnailUrl`, `width`, `height`.

`PostAuthor` exposes `id`, `name`, `handle`, `url`, `avatarUrl`. For Instagram, only `id` and `handle` are populated from the stream response — call `craft.socialStream.getProfile()` for richer account data (username, profile picture, follower count).

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

---

## Cron Setup

Two CLI commands should be run on a schedule:

### Token Refresh

Refreshes the long-lived token before it expires (recommended: daily at 3 AM):

```bash
0 3 * * * cd /path/to/craft && php craft social-stream/token/refresh
```

Accepts `--site=<handle>` to refresh a specific site, or refreshes all sites when omitted.

### Stream Refresh

Pre-warms the cache so front-end requests never trigger a cold API call (recommended: every 15 minutes):

```bash
*/15 * * * * cd /path/to/craft && php craft social-stream/refresh
```

Accepts `--site=<handle>` to refresh a specific site, or refreshes all sites when omitted.

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

When a token is connected, the **Connection** tab displays a health panel showing:

- **Token status** — green (valid), amber (expiring within 7 days), red (expired)
- **Token expiry date**
- **Last successful fetch** timestamp
- **Last error** message and timestamp
- **Rate-limit cooldown** — active or inactive
- **API version** in use

Two action buttons are available:

- **Test Connection** — makes a `GET /me` call and displays the account name and type
- **Refresh Stream Now** — queues a background stream refresh immediately

---

## Multi-Site Support

Each Craft site can connect a different Instagram account with independent settings. Use the site switcher at the top of the settings page to configure each site.

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
- **Cache clearing**: use **Utilities > Caches > Invalidate data caches > Social Stream data** in the CP, or run `php craft invalidate-tags/social-stream` from the CLI.

---

## Troubleshooting

### Token has expired

The token must be refreshed before its 60-day expiry. Set up the cron job (`php craft social-stream/token/refresh`) to handle this automatically. You can also re-authorise from the **Connection** tab.

### Wrong account type

The Instagram Graph API requires a **Business** or **Creator** account. Personal accounts are not supported. Convert your account in Instagram's settings under **Account > Switch to professional account**.

### Rate limited

If the Instagram API returns a rate-limit error (HTTP 429), the plugin enters a 15-minute cooldown. During this time, stale cached data is served instead of making API calls. The cooldown status is visible in the Connection Health panel.

### Missing fields

Some fields (e.g. `like_count`, `comments_count`) may not be returned depending on your app's permissions or the media type. The plugin defaults missing values to `null` gracefully. Ensure your Meta App has the required permissions approved.

### Using the health panel

The Connection Health panel on the **Connection** tab provides at-a-glance diagnostics:

- A red token status means the token has expired — re-authorise or check your cron setup.
- A "Last Error" entry shows the most recent API failure.
- An active rate-limit cooldown means the API is temporarily suppressed.

Use the **Test Connection** button to verify the API is responding correctly.

---

## API Version

The plugin targets Instagram Graph API **v21.0** via `graph.facebook.com`. The version is centralised as a constant (`InstagramProvider::API_VERSION`) and displayed in the Connection Health panel.

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
